<?php
namespace Civi\Api4\Action\ContributionRecur;

use Civi\Api4\ContributionRecur;
use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\Generic\Result;
use Civi\Api4\PaymentProcessor;
use Civi\Api4\PaymentToken;
use CRM_Core_DAO;
use League\Csv\Reader as CsvReader;
use League\Csv\Statement as CsvStatement;
use Symfony\Component\Filesystem\Exception\IOException;

/**
 * Migrates Ingenico tokens specified in a CSV to Adyen tokens
 * @method setPath(string $path)
 */
class MigrateTokens extends AbstractAction {

  /**
   * @var string path to a CSV with new token information
   * @required
   */
  protected $path;

  /**
   * @var int
   */
  protected $ingenicoProcessorId;

  /**
   * @var int
   */
  protected $adyenProcessorId;

  public function _run(Result $result) {
    if (!is_readable($this->path)) {
      throw new IOException("Can't read $this->path");
    }

    // Look up IDs for payment processors
    $this->ingenicoProcessorId = $this->getPaymentProcessorId('ingenico');
    $this->adyenProcessorId = $this->getPaymentProcessorId('adyen');

    $reader = CsvReader::createFromPath($this->path)->setDelimiter(",")->setHeaderOffset(0);
    // TODO: check for reqyired columns: echodata, recurringDetailReference, and shopperReference
    $stmt = new CsvStatement();
    // TODO: ->offset($this->offset)
    // TODO: ->limit($this->batch_size);

    $records = $stmt->process($reader);
    $alreadyImported = [];
    foreach ($records as $record) {
      // for some reason the tokens are listed three times in the file for prod.
      if (!key_exists($record['echodata'],  $alreadyImported)) {
        $this->migrateToken(
          $record['echodata'],
          $record['recurringDetailReference'],
          $record['shopperReference'],
          $result
        );
      }
      $alreadyImported[$record['echodata']] = true;
    }
  }

  private function getPaymentProcessorId($name) {
    return PaymentProcessor::get(FALSE)
      ->addWhere('is_test', '=', 0)
      ->addWhere('name', '=', $name)
      ->setSelect(['id'])
      ->execute()->first()['id'];
  }

  protected function migrateToken($oldToken, $newToken, $invoiceId, Result $result) {
    $existingTokenId = CRM_Core_DAO::singleValueQuery(
      'SELECT id FROM civicrm_payment_token
        WHERE token = %1
        AND payment_processor_id = %2
      ', [
      1 => [$oldToken, 'String'],
      2 => [$this->ingenicoProcessorId, 'Integer']
    ]);
    if (!$existingTokenId) {
      $result['missing_tokens'][] = $oldToken;
      return;
    }
    PaymentToken::update(FALSE)
      ->setValues([
        'payment_processor_id' => $this->adyenProcessorId,
        'token' => $newToken
      ])
      ->addWhere('id', '=', $existingTokenId)
      ->execute();
    ContributionRecur::update(FALSE)
      ->setValues([
        'payment_processor_id' => $this->adyenProcessorId,
        'invoice_id' => $invoiceId
      ])
      ->addWhere('payment_token_id', '=', $existingTokenId)
      ->execute();
    \Civi::log('wmf')->info(
      'Migrated Ingenico token {oldToken} with id {existingTokenId} to Adyen token {newToken} with invoice ID {invoiceId}',
      [
        'oldToken' => $oldToken,
        'existingTokenId' => $existingTokenId,
        'newToken' => $newToken,
        'invoiceId' => $invoiceId,
      ]
    );
    $result['migrated'][] = [
      'ingenico_token' => $oldToken,
      'payment_token_id' => $existingTokenId,
      'adyen_token' => $newToken,
      'invoice_id' => $invoiceId,
    ];
  }
}

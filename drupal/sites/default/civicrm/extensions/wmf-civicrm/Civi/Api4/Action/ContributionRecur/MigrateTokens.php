<?php
namespace Civi\Api4\Action\ContributionRecur;

use Civi\API\Exception\UnauthorizedException;
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
 * @method setBatch(int $batch)
 * @method setOffset(int $offset)
 */
class MigrateTokens extends AbstractAction {

  /**
   * @var string path to a CSV with new token information
   * @required
   */
  protected $path;

  /**
   * @var int How many rows to process
   */
  protected $batch;

  /**
   * @var int Which row to start from
   */
  protected $offset;

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
    if ($this->offset) {
      $stmt = $stmt->offset($this->offset);
    }
    if ($this->batch) {
      $stmt = $stmt->limit($this->batch);
    }

    $records = $stmt->process($reader);
    $alreadyImported = [];
    foreach ($records as $record) {
      // The CSV for prod seems to have multiple identical rows. So we want to skip rows that
      // are just the same as we have already handled. BUT... if we have two rows with the
      // same Ingenico tokens and different Adyen tokens, we want to process both rows. So
      // make a row ID with both tokens.
      $rowIdentifier = $record['echodata'] . $record['recurringDetailReference'];
      if (!key_exists($rowIdentifier,  $alreadyImported)) {
        $this->migrateToken(
          $record['echodata'],
          $record['recurringDetailReference'],
          $record['shopperReference'],
          $result
        );
        $alreadyImported[$rowIdentifier] = true;
      }
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
    $existingTokenResults = CRM_Core_DAO::executeQuery(
      'SELECT t.*, COUNT(*) AS num
        FROM civicrm_payment_token t
        LEFT JOIN civicrm_contribution_recur r ON r.payment_token_id = t.id
        WHERE t.token = %1
        AND t.payment_processor_id = %2
      ', [
      1 => [$oldToken, 'String'],
      2 => [$this->ingenicoProcessorId, 'Integer']
    ]);
    if (!$existingTokenResults->fetch()) {
      $result['missing_tokens'][] = $oldToken;
      return;
    }
    $existingTokenId = $existingTokenResults->id;
    if ($existingTokenResults->num > 1) {
      // Multiple recurring rows are attached to the same Ingenico token.
      // The CSV export seems to have distinct Adyen tokens for each recurring row,
      // so here we need to make a copy of the Ingenico token and point all the
      // contribution_recur rows which DON'T match the current CSV row over to
      // the copy.
      $this->copyTokenAndMoveNonMatchingRecurs($existingTokenResults, $invoiceId, $result);
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

  /**
   * Create a new token row, and update all contribution_recur rows that
   * ARE NOT associated with the given invoiceId, to point to the new row.
   * @param CRM_Core_DAO $existingTokenResults
   * @param string $invoiceId
   * @param Result $result
   * @throws \CRM_Core_Exception
   * @throws UnauthorizedException
   */
  protected function copyTokenAndMoveNonMatchingRecurs(
    CRM_Core_DAO $existingTokenResults, string $invoiceId, Result $result
  ) {
    // Find the ct_id from the invoice ID, to get a search string for contribution invoices
    // Also ensures that the thing we're feeding to the SQL string is just digits.
    $matches = [];
    preg_match('/^\d+/', $invoiceId, $matches);
    // TODO i suppose they could give us a malformed invoice ID
    $ctId = $matches[0];
    $recurIdMatchingInvoice = CRM_Core_DAO::singleValueQuery('
      SELECT r.id
      FROM civicrm_contribution_recur r
      INNER JOIN civicrm_contribution c ON c.contribution_recur_id = r.id
      WHERE c.invoice_id LIKE %1
      AND r.payment_token_id = %2
      ',
      [
        1 => [$ctId . '.%', 'String'],
        2 => [$existingTokenResults->id, 'Integer']
      ]
    );
    // TODO what if we don't actually find it this way? Fall back to contribution tracking?
    $newTokenId = PaymentToken::create(FALSE)
      ->setValues([
        'contact_id' => $existingTokenResults->contact_id,
        'payment_processor_id' => $existingTokenResults->payment_processor_id,
        'token' => $existingTokenResults->token,
        'ip_address' => $existingTokenResults->ip_address
      ])
      ->execute()
      ->first()['id'];
    ContributionRecur::update(FALSE)
      ->addWhere('payment_token_id', '=',$existingTokenResults->id)
      ->addWhere('id', '<>', $recurIdMatchingInvoice)
      ->setValues([
        'payment_token_id' => $newTokenId
      ])
      ->execute()
      ->rowCount;
    $result['copied_token_for_invoices'][] = $invoiceId;
  }
}

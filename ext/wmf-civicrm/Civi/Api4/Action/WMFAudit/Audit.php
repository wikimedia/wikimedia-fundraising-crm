<?php

namespace Civi\Api4\Action\WMFAudit;

use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\Generic\Result;
use Civi\Api4\SettlementTransaction;
use Civi\Api4\WMFAudit;
use Civi\WMFQueueMessage\AuditMessage;

/**
 * Settle transaction.
 *
 * @method $this setValues(array $values)
 * @method $this setProcessSettlement(string $actionToTake)
 */
class Audit extends AbstractAction {

  /**
   * Settlement message.
   *
   * @var array
   */
  protected array $values = [];

  protected ?string $processSettlement = NULL;

  /**
   * This function updates the settled transaction with new fee & currency conversion data.
   *
   * @param \Civi\Api4\Generic\Result $result
   *
   * @return void
   * @throws \CRM_Core_Exception
   */
  public function _run(Result $result): void {
    $message = new AuditMessage($this->values);
    $isMissing = !$message->getExistingContributionID();

    if ($this->processSettlement) {
      // For now let's start tracking the messages...
      // Next will be to settle them.
      $record = $message->normalize();
      $record['date'] = date('Ymdhis', $record['date']);
      $record['settled_date'] = date('Y-m-d H:i:s T', $record['settled_date']);
      if ($record['gateway'] === 'gravy') {
        // This is very much tbd - but for adyen audit files we want to match
        // this field...
        $record['audit_file_gateway_txn_id'] = $record['backend_processor_txn_id'];
      }
      SettlementTransaction::save(FALSE)
        ->addRecord(['type' => $message->getTransactionType()] + $record)
        ->setMatch(['gateway_txn_id', 'type'])
        ->execute();
      // Here we would ideally queue but short term we will probably process in real time on specific files
      // as we test.
      // @todo - create queue option (after maybe some testing with this).
      // For now this only kicks in when run manually and only on settlement reports from adyen
      // as only those pass up the settled reference.
      if (!$isMissing && !empty($record['settlement_batch_reference'])) {
        WMFAudit::settle(FALSE)
          ->setValues([
            'gateway' => $record['gateway'],
            'gateway_txn_id' => $record['gateway_txn_id'],
            'contribution_id' => $message->getExistingContributionID(),
            'settled_date' => $record['settled_date'],
            'settled_currency' => $record['settled_currency'],
            'settlement_batch_reference' => $record['settlement_batch_reference'],
            'settled_total_amount' => $record['settled_total_amount'],
            'settled_fee_amount' => $record['settled_fee_amount'],
          ])->execute();
      }
    }
   // @todo - we would ideally augment the missing messages here from the Pending table
    // allowing us to drop 'log_hunt_and_send'
    // also @todo if we are unable to find the extra data then queue to (e.g) a missing
    // queue - this would allow us to process each audit file only once & the transactions would
    // be 'in the system' from then on until resolved. I guess the argument for the files is that
    // if they do not get processed for any reason the file sits there until they are truly processed....
    $record = [
      'is_negative' => $message->isNegative(),
      'message' => $message->normalize(),
      'payment_method' => $message->getPaymentMethod(),
      // Caller still expects the ambiguous 'main'.
      'audit_message_type' => $message->getAuditMessageType() === 'settled' ? 'main' : $message->getAuditMessageType(),
      'is_missing' => $isMissing,
      'is_settled' => $message->isSettled(),
    ];
    $result[] = $record;
  }

  public static function fields(): array {
    $message = new AuditMessage([]);
    return $message->getFields();
  }

}

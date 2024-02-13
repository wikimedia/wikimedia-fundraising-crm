<?php namespace queue2civicrm\fredge;

use Civi\Api4\PaymentsInitial;
use Civi\WMFException\FredgeDataValidationException;
use SmashPig\Core\DataStores\PaymentsInitialDatabase;
use SmashPig\Core\DataStores\PendingDatabase;
use Civi\WMFQueue\QueueConsumer;
use Civi\WMFException\WMFException;

class PaymentsInitQueueConsumer extends QueueConsumer {

  /**
   * Validate and store messages from the payments-init queue
   *
   * @param array $message
   *
   * @throws \Civi\WMFException\WMFException|\SmashPig\Core\DataStores\DataStoreException
   */
  public function processMessage(array $message): void {
    $logId = "{$message['gateway']}-{$message['order_id']}";
    \Civi::log('wmf')->info(
      'fredge: Beginning processing of payments-init message for {log_id}',
      ['log_id' => $logId]
    );

    // Delete corresponding pending rows if this contribution failed.
    // The DonationQueueConsumer will delete pending rows for successful
    // contributions, and we don't want to be too hasty.
    // Leave details for payments still open for manual review.
    // We make an exception for Adyen and Astropay because those
    // processors allow donors to reuse the merchant reference by
    // reloading the hosted page. Note that this means we can't
    // implement orphan rectifiers for those gateways.
    $processorAllowsRepeat = in_array(['gateway'], ['astropay', 'adyen']);
    if (
      PaymentsInitialDatabase::isMessageFailed($message) &&
      !$processorAllowsRepeat
    ) {
      \Civi::log('wmf')->info('fredge" Deleting pending row for failed payment {log_id}', [
        'log_id' => $logId,
      ]);
      PendingDatabase::get()->deleteMessage($message);
    }

    $result = PaymentsInitial::get(FALSE)
      ->addWhere('contribution_tracking_id', '=', $message['contribution_tracking_id'])
      ->addWhere('order_id', '=', $message['order_id'])
      ->execute()->first();

    $data = fredge_prep_data($message, 'payments_initial', $logId, FALSE);

    if ($result) {
      $data['id'] = $result['id'];
    }
    try {
      PaymentsInitial::save(FALSE)->setRecords([$data])->execute();
    }
    catch (\CRM_Core_Exception $e) {
      if ($e->getErrorCode() === 'mandatory_missing') {
        $error = $logId . ": Expected field " . implode($e->getErrorData()['fields']) . " bound for table payments_fraud not present! Dropping message on floor.";
        throw new FredgeDataValidationException($error);
      }
      throw $e;
    }
  }

}

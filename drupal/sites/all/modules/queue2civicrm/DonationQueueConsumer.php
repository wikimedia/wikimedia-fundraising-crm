<?php namespace queue2civicrm;

use SmashPig\Core\DataStores\PendingDatabase;
use Queue2civicrmTrxnCounter;
use SmashPig\Core\UtcDate;
use wmf_common\TransactionalWmfQueueConsumer;
use Civi\WMFException\WMFException;
use DonationStatsCollector;

class DonationQueueConsumer extends TransactionalWmfQueueConsumer {

  /**
   * Feed queue messages to wmf_civicrm_contribution_message_import,
   * logging and merging any extra info from the pending db.
   *
   * @param array $message
   * @throws \Civi\WMFException\WMFException
   */
  public function processMessage($message) {
    /**
     * prepare data for logging
     */
    $log = [
      'gateway' => $message['gateway'],
      'gateway_txn_id' => $message['gateway_txn_id'],
      'data' => json_encode( $message ),
      'timestamp' => time(),
      'verified' => 0,
    ];
    $logId = _queue2civicrm_log( $log );

    // If more information is available, find it from the pending database
    // FIXME: combine the information in a SmashPig job a la Adyen, not here
    if (isset($message['completion_message_id'])) {
      $pendingDbEntry = PendingDatabase::get()->fetchMessageByGatewayOrderId(
        $message['gateway'],
        $message['order_id']
      );
      if ($pendingDbEntry) {
        // Sparse messages should have no keys at all for the missing info,
        // rather than blanks or junk data. And $msg should always have newer
        // info than the pending db.
        $message = $message + $pendingDbEntry;
        // $pendingDbEntry has a pending_id key, but $msg doesn't need it
        unset($message['pending_id']);
      }
      else {
        // If the contribution has already been imported, this check will
        // throw an exception that says to drop it entirely, not re-queue.
        wmf_civicrm_check_for_duplicates(
          $message['gateway'],
          $message['gateway_txn_id']
        );
        // Otherwise, throw an exception that tells the queue consumer to
        // requeue the incomplete message with a delay.
        $errorMessage = "Message {$message['gateway']}-{$message['gateway_txn_id']} " .
          "indicates a pending DB entry with order ID {$message['order_id']}, " .
          "but none was found.  Requeueing.";
        throw new WMFException(WMFException::MISSING_PREDECESSOR, $errorMessage);
      }
    }

    $contribution = wmf_civicrm_contribution_message_import($message);

    // update the log if things went well
    if ($logId) {
      $log['cid'] = $logId;
      $log['verified'] = 1;
      $log['timestamp'] = time();
      _queue2civicrm_log( $log );
    }

    $DonationStatsCollector = DonationStatsCollector::getInstance();
    $DonationStatsCollector->recordDonationStats($message, $contribution);

    /**
     * === Legacy Donations Counter implementation ===
     */
    $age = UtcDate::getUtcTimestamp() - UtcDate::getUtcTimestamp($contribution['receive_date']);
    $counter = Queue2civicrmTrxnCounter::instance();
    $counter->increment($message['gateway']);
    $counter->addAgeMeasurement($message['gateway'], $age);
    /**
     * === End of Legacy Donations Counter implementation ===
     */

    if (!empty($message['order_id'])) {
      // Delete any pending db entries with matching gateway and order_id
      PendingDatabase::get()->deleteMessage($message);
    }
  }
}

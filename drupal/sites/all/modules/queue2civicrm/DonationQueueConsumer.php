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
    // If the contribution has already been imported, this check will
    // throw an exception that says to drop it entirely, not re-queue.
    wmf_civicrm_check_for_duplicates($message);

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
        // Throw an exception that tells the queue consumer to
        // requeue the incomplete message with a delay.
        $errorMessage = "Message {$message['gateway']}-{$message['gateway_txn_id']} " .
          "indicates a pending DB entry with order ID {$message['order_id']}, " .
          "but none was found.  Requeueing.";
        throw new WMFException(WMFException::MISSING_PREDECESSOR, $errorMessage);
      }
    }
    // Donations through the donation queue are most likely online gifts unless stated otherwise
    if (empty($message['gift_source'])) {
      $message['gift_source'] = "Online Gift";
    }
    // import the contribution here!
    $contribution = wmf_civicrm_contribution_message_import($message);

    // record other donation stats such as gateway
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

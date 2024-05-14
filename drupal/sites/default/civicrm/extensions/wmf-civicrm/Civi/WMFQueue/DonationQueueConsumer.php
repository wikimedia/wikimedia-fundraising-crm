<?php

namespace Civi\WMFQueue;

use Civi\WMFException\WMFException;
use Civi\WMFStatistic\DonationStatsCollector;
use Civi\WMFStatistic\ImportStatsCollector;
use Civi\WMFStatistic\PrometheusReporter;
use Civi\WMFStatistic\Queue2civicrmTrxnCounter;
use SmashPig\Core\DataStores\PendingDatabase;
use SmashPig\Core\UtcDate;
use SmashPig\PaymentProviders\IDeleteRecurringPaymentTokenProvider;
use SmashPig\PaymentProviders\PaymentProviderFactory;
use Statistics\Collector\AbstractCollector;

class DonationQueueConsumer extends TransactionalQueueConsumer {

  private AbstractCollector $statsCollector;

  public function initiateStatistics(): void {
    $this->statsCollector = DonationStatsCollector::getInstance();
    $this->statsCollector->startDefaultTimer();
  }

  public function reportStatistics(int $totalMessagesDequeued): void {
    $this->statsCollector->endDefaultTimer();

    $this->statsCollector->addStat('total_messages_dequeued', $totalMessagesDequeued);

    if ($this->statsCollector->exists('message_import_timers')) {
      $this->statsCollector->addStat('total_messages_import_time', $this->statsCollector->sum('message_import_timers'));
      $this->statsCollector->del('message_import_timers');
    }

    $this->statsCollector->export();
    DonationStatsCollector::tearDown();

    // export civicrm import timer stats
    ImportStatsCollector::getInstance()->export();

    /**
     * === Legacy Donations Counter implementation ===
     *
     * Note that this might be a little whack.  At least, it feels a little sloppy.
     * We might consider specifying the names of gateways to keep track of, rather than auto-generate
     * the gateways to keep track of during queue consumption. With the latter (current) method,
     * we'll only report to prometheus when there are > 0 msgs consumed from the queue - meaning if
     * there are no msgs for a particular gateway, that fact will not get reported to prometheus.
     *
     * TODO: metrics stuff should be a hook
     */
    $counter = Queue2civicrmTrxnCounter::instance();
    $metrics = [];
    foreach ($counter->getTrxnCounts() as $gateway => $count) {
      $metrics["${gateway}_donations"] = $count;
    }
    $metrics['total_donations'] = $counter->getCountTotal();
    $this->recordMetric('queue2civicrm', $metrics);
    $ageMetrics = [];
    foreach ($counter->getAverageAges() as $gateway => $age) {
      $ageMetrics["${gateway}_message_age"] = $age;
    }
    $this->recordMetric('donation_message_age', $ageMetrics);
  }

  protected function recordMetric($namespace, $metrics) {
    $prometheusPath = \Civi::settings()->get('metrics_reporting_prometheus_path');
    $reporter = new PrometheusReporter($prometheusPath);
    $reporter->reportMetrics($namespace, $metrics);
  }

  /**
   * Feed queue messages to wmf_civicrm_contribution_message_import,
   * logging and merging any extra info from the pending db.
   *
   * @param array $message
   *
   * @throws \CRM_Core_Exception
   * @throws \Civi\WMFException\WMFException
   * @throws \SmashPig\Core\DataStores\DataStoreException
   * @throws \Statistics\Exception\StatisticsCollectorException
   */
  public function processMessage(array $message): void {
    // no need to insert contribution, return empty array is enough
    if (isset($message['monthly_convert_decline']) && $message['monthly_convert_decline']) {
      $this->removeRecurringToken($message);
      return;
    }
    // If the contribution has already been imported, this check will
    // throw an exception that says to drop it entirely, not re-queue.
    $this->checkForDuplicates($message);

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


  /**
   * Throw an exception if a contribution already exists
   *
   * @todo - perhaps this could be a function on the message class
   * e.g validateNotExists().
   *
   * @param array $message
   *
   * @throws \Civi\WMFException\WMFException
   * @throws \CRM_Core_Exception
   */
  private function checkForDuplicates(array $message) {
    if (
      empty($message['gateway']) ||
      empty($message['gateway_txn_id'])
    ) {
      throw new WMFException(
        WMFException::CIVI_REQ_FIELD,
        'Missing required field: ' .
        (empty($message['gateway']) ? 'gateway, ' : '') .
        (empty($message['gateway_trxn_id']) ? 'gateway_trxn_id, ' : '')
      );
    }

    if (\CRM_Core_DAO::singleValueQuery(
      'SELECT count(*)
    FROM wmf_contribution_extra cx
    WHERE gateway = %1 AND gateway_txn_id = %2', [
      1 => [$message['gateway'], 'String'],
      2 => [$message['gateway_txn_id'], 'String'],
    ])) {
      throw new WMFException(
        WMFException::DUPLICATE_CONTRIBUTION,
        'Contribution already exists. Ignoring message.'
      );
    }
  }

  private function removeRecurringToken(array $message): void {
    wmf_common_create_smashpig_context('donation_queue_process_message', $message['gateway']);
    $provider = PaymentProviderFactory::getProviderForMethod(
      $message['payment_method']
    );
    // todo: need to add this deleteRecurringPaymentToken function for other payment gateways if we pre tokenized for recurring
    if ($provider instanceof IDeleteRecurringPaymentTokenProvider) {
      // handle remove recurring token for on-time donor with post monthly convert
      \Civi::log('wmf')->notice('decline-recurring:' . $message['gateway'] . ' ' . $message['payment_method'] . ': decline recurring with order id ' . $message['order_id']);
      $result = $provider->deleteRecurringPaymentToken($message);
      $logMessage = "decline-recurring: For order id: {$message['order_id']}, delete recurring payment token with status " . ($result ? 'success' : 'failed');
      \Civi::log('wmf')->info($logMessage);
    }
  }

}

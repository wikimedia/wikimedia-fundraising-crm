<?php

namespace Civi\WMFQueue;

use Civi\Api4\Activity;
use Civi\Api4\Contribution;
use Civi\Api4\ContributionRecur;
use Civi\Api4\PaymentToken;
use Civi\Api4\WMFContact;
use Civi\Core\Exception\DBQueryException;
use Civi\WMFException\WMFException;
use Civi\WMFHelper\ContributionRecur as ContributionRecurHelper;
use Civi\WMFHelper\PaymentProcessor as PaymentProcessorHelper;
use Civi\WMFQueueMessage\DonationMessage;
use Civi\WMFQueueMessage\RecurDonationMessage;
use Civi\WMFStatistic\DonationStatsCollector;
use Civi\WMFStatistic\ImportStatsCollector;
use Civi\WMFStatistic\PrometheusReporter;
use Civi\WMFStatistic\Queue2civicrmTrxnCounter;
use Civi\WMFTransaction;
use SmashPig\Core\DataStores\PendingDatabase;
use SmashPig\Core\DataStores\QueueWrapper;
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
      $metrics["{$gateway}_donations"] = $count;
    }
    $metrics['total_donations'] = $counter->getCountTotal();
    $this->recordMetric('queue2civicrm', $metrics);
    $ageMetrics = [];
    foreach ($counter->getAverageAges() as $gateway => $age) {
      $ageMetrics["{$gateway}_message_age"] = $age;
    }
    $this->recordMetric('donation_message_age', $ageMetrics);
  }

  protected function recordMetric(string $namespace, array $metrics): void {
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
   * @throws \SmashPig\Core\ConfigurationKeyException
   * @throws \SmashPig\Core\DataStores\DataStoreException
   * @throws \Statistics\Exception\StatisticsCollectorException
   */
  public function processMessage(array $message): void {
    // If more information is available, find it from the pending database
    // FIXME: combine the information in a SmashPig job a la Adyen, not here
    if (isset($message['completion_message_id'])) {
      $pendingDbEntry = PendingDatabase::get()->fetchMessageByGatewayOrderId(
        $message['gateway'],
        $message['order_id'],
        (string) $message['gateway_txn_id']
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
    $contribution = $this->doImport($message);

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
   * Try to import a transaction message into CiviCRM, otherwise
   * throw an exception.
   *
   * @param array $msg
   *
   * @return array Contribution as inserted
   *
   * @throws \CRM_Core_Exception
   * @throws \Civi\WMFException\WMFException
   * @throws \SmashPig\Core\ConfigurationKeyException
   * @throws \SmashPig\Core\DataStores\DataStoreException
   * @throws \Statistics\Exception\StatisticsCollectorException
   */
  private function doImport(array &$msg): array {
    $message = DonationMessage::getWMFMessage($msg);
    $message->setIsPayment(TRUE);
    $importTimerName = $message->isRecurring() ? 'wmf_civicrm_recurring_message_import' : 'wmf_civicrm_contribution_message_import';
    $this->startAction($importTimerName);
    $this->startAction('verify_and_stage');
    $msg = $message->normalize();
    $message->validate();
    if (!$message->getContributionTrackingID()) {
      $msg = $this->addContributionTrackingIfMissing($msg);
      $message->setContributionTrackingID(empty($msg['contribution_tracking_id']) ? NULL : $msg['contribution_tracking_id']);
    }
    $this->stopAction('verify_and_stage');

    $this->startAction('create_contact');
    $contact = WMFContact::save(FALSE)
      ->setMessage($msg)
      ->execute()->first();
    $msg['contact_id'] = $contact['id'];
    $this->stopAction("create_contact");

    // Make new recurring record if necessary
    if ($message->isRecurring()) {
      if ($message->getContributionRecurID() && ContributionRecurHelper::gatewayManagesOwnRecurringSchedule($message->getGateway())) {
        // If parent record is mistakenly marked as Completed, Cancelled, or Failed, reactivate it
        ContributionRecurHelper::reactivateIfInactive([
          'contribution_status_id' => $message->getExistingContributionRecurValue('contribution_status_id'),
          'id' => $message->getContributionRecurID(),
        ], $message->getDate());
      }
      if (!$message->getContributionRecurID()) {
        // This logic was copied from the old RecurringQueueConsumer for PayPal subscription payments created before the contribution recur row is created
        if ($message->isPaypal() && strpos($msg['subscr_id'], 'I-') === FALSE) {
          \Civi::log('wmf')->notice('recurring: Msg does not have a matching recurring record in civicrm_contribution_recur; requeueing for future processing.');
          throw new WMFException(WMFException::MISSING_PREDECESSOR, "Missing the initial recurring record for subscr_id {$msg['subscr_id']}");
        }
        \Civi::log('wmf')->info('Creating new contribution_recur record while processing a subscr_payment');
        $this->startAction('message_contribution_recur_insert');
        $this->importContributionRecur($message, $msg, $msg['contact_id']);
        $this->stopAction('message_contribution_recur_insert');
      }
      elseif ($message->isPaypal() || $message->isAutoRescue()) {
        // We are looking at a PayPal or auto-rescue payment
        // that has come out of the recurring queue.
        // We need to manage their status and (for PayPal) the next scheduled date.
        $recurUpdate = ContributionRecur::update(FALSE)
          ->setValues([
            'contribution_status_id:name' => 'In Progress',
          ])
          ->addWhere('id', '=', $message->getContributionRecurID());
        if ($message->isPaypal()) {
          // Other than for PayPal this is done elsewhere, but we want to display
          // something meaningful in the UI for PayPal.
          $recurUpdate->addValue('next_sched_contribution_date', \CRM_Core_Payment_Scheduler::getNextContributionDate([
            'frequency_interval' => $message->getExistingContributionRecurValue('frequency_interval'),
            'frequency_unit' => $message->getExistingContributionRecurValue('frequency_unit'),
            'cycle_day' => $message->getExistingContributionRecurValue('cycle_day'),
          ]));
        }

        if ($message->isAutoRescue()) {
          // Processor retry completed successfully
          Activity::create(FALSE)
            ->addValue('date', $msg['date'])
            ->addValue('activity_type_id:name', 'Recurring Processor Retry - Success')
            ->addValue('status_id:name', 'Completed')
            ->addValue('subject', 'Successful processor retry with rescue reference: ' . $msg['rescue_reference'])
            ->addValue('details', 'Rescue reference: ' . $msg['rescue_reference'])
            ->addValue('source_contact_id', $msg['contact_id'])
            ->addValue('target_contact_id', $msg['contact_id'])
            ->addValue('source_record_id', $message->getContributionRecurID())
            ->execute();
        }

        $recurUpdate->execute();
      }
    }

    // Insert the contribution record.
    $this->startAction('message_contribution_insert');
    $contribution = $this->importContribution($message, $msg);
    $this->stopAction('message_contribution_insert');

    if ($message->getContributionTrackingID()
      && !$message->getRecurringPriorContributionValue('id')) {
      QueueWrapper::push('contribution-tracking', [
        'id' => $message->getContributionTrackingID(),
        'contribution_id' => $contribution['id'],
      ]);
      \Civi::log('wmf')->info('wmf_civicrm: Queued update to contribution_tracking for {id}', ['id' => $message->getContributionTrackingID()]);
    }

    // Need to get this full name before ending the timer
    $uniqueTimerName = ImportStatsCollector::getInstance()->getUniqueNamespace($importTimerName);
    $this->stopAction($importTimerName);

    DonationStatsCollector::getInstance()
      ->addStat("message_import_timers", ImportStatsCollector::getInstance()->getTimerDiff($uniqueTimerName));

    return $contribution;
  }

  /**
   * Insert the contribution record.
   *
   * This is an internal method, you must be looking for
   *
   * @param \Civi\WMFQueueMessage\DonationMessage $message
   * @param array $msg
   *
   * @return array
   *
   * @throws \CRM_Core_Exception
   * @throws \Civi\Core\Exception\DBQueryException
   * @throws \Civi\WMFException\WMFException
   */
  private function importContribution(DonationMessage $message, array $msg): array {
    $contribution = [
      'contact_id' => $msg['contact_id'],
      'total_amount' => $msg['gross'],
      'financial_type_id' => $msg['financial_type_id'],
      'payment_instrument_id' => $msg['payment_instrument_id'],
      'fee_amount' => $msg['fee'],
      'net_amount' => $msg['net'],
      'trxn_id' => $msg['trxn_id'],
      'receive_date' => $message->getDate(),
      'currency' => $msg['currency'],
      'contribution_recur_id' => $message->getContributionRecurID(),
      'debug' => TRUE,
      'invoice_id' => $message->getInvoiceID(),
    ];

    // Set no_thank_you to recurring if it's the 2nd+ of any recurring payments
    if ($message->getRecurringPriorContributionValue('id') && $message->getExistingContributionRecurValue('frequency_unit') !== 'year') {
      $contribution['contribution_extra.no_thank_you'] = 'recurring';
    }

    $contribution['contribution_status_id'] = $message->getContributionStatusID();

    $customFields = (array) Contribution::getFields(FALSE)
      ->addWhere('custom_field_id', 'IS NOT EMPTY')
      ->addSelect('name')
      ->execute()->indexBy('name');
    $contribution += array_intersect_key($msg, $customFields);

    \Civi::log('wmf')->debug('wmf_civicrm: Contribution array for contribution create {contribution}: ', ['contribution' => $contribution, TRUE]);
    try {
      $contributionAction = Contribution::create(FALSE)
        ->setValues(array_merge($contribution, ['skipRecentView' => 1]));
      $contribution_result = $contributionAction->execute()->first();
      \Civi::log('wmf')->debug('wmf_civicrm: Successfully created contribution {contribution_id} for contact {contact_id}', [
        'contribution_id' => $contribution_result['id'],
        'contact_id' => $contribution['contact_id'],
      ]);
      if (!empty($msg['referral_id']) && $msg['referral_id'] != $contribution['contact_id']) {
        \Civi::log('wmf')->debug('wmf_civicrm: Contribution {contribution_id} on Contact ID {contribution_contact_id} was referred by Contact ID {original_contact_id}', [
          'contribution_id' => $contribution_result['id'],
          'contribution_contact_id' => $contribution['contact_id'],
          'original_contact_id' => $msg['referral_id'],
        ]);
        Activity::create(FALSE)
          ->addValue('activity_type_id:name', 'Contact referral')
          ->addValue('source_contact_id', $msg['referral_id'])
          ->addValue('status_id:name', 'Completed')
          ->addValue('subject', 'Donor was referred')
          ->addValue('details', json_encode([
            'contribution_id' => $contribution_result['id'],
            'referral_contact_id' =>  $msg['referral_id'],
            'contact_id' => $contribution['contact_id']
          ]))
          ->addValue('target_contact_id', $contribution['contact_id'])
          ->addValue('source_record_id', $contribution_result['id'])
          ->execute();
      }
      return $contribution_result;
    }
    catch (\CRM_Core_Exception $e) {
      \Civi::log('wmf')->info('wmf_civicrm: Error inserting contribution: {message} {code}', ['message' => $e->getMessage(), 'code' => $e->getCode()]);

      if (empty($contribution_result) && array_key_exists('invoice_id', $contribution)) {
        \Civi::log('wmf')->info('wmf_civicrm : Checking for duplicate on invoice ID {invoice_id}', ['invoice_id' => $contribution['invoice_id']]);
        $invoice_id = $contribution['invoice_id'];
        if (Contribution::get(FALSE)->addWhere('invoice_id', '=', $invoice_id)->execute()->first()){
          // We can't retry the insert here because the original API
          // error has marked the Civi transaction for rollback.
          // This WMFException code has special handling in the
          // WmfQueueConsumer that will alter the invoice_id before
          // re-queueing the message.
          throw new WMFException(
            WMFException::DUPLICATE_INVOICE,
            'Duplicate invoice ID, should modify and retry',
            $e->getErrorData()
          );
        }
      }
      throw $e;
    }
  }

  /**
   * Insert the recurring contribution record
   *
   * @param \Civi\WMFQueueMessage\RecurDonationMessage $message
   * @param array $msg
   * @param integer $contact_id
   *
   * @throws \Civi\WMFException\WMFException
   * @throws \CRM_Core_Exception
   *
   */
  private function importContributionRecur(RecurDonationMessage $message, array $msg, int $contact_id): void {
    \Civi::log('wmf')->info('wmf_civicrm_import: Attempting to insert new recurring subscription: {recurring_transaction_id}', ['recurring_transaction_id' => $message->getSubscriptionID() ?: $msg['gateway_txn_id']]);
    $msg['frequency_unit'] = $msg['frequency_unit'] ?? 'month';
    $msg['frequency_interval'] = isset($msg['frequency_interval']) ? (integer) $msg['frequency_interval'] : 1;
    $msg['installments'] = isset($msg['installments']) ? (integer) $msg['installments'] : 0;
    $msg['cancel'] = isset($msg['cancel']) ? (integer) $msg['cancel'] : 0;

    // Allowed frequency_units
    $frequency_units = ['month', 'year'];
    if (!in_array($msg['frequency_unit'], $frequency_units)) {
      $error_message = t(
        'Invalid `frequency_unit` specified [!frequency_unit]. Supported frequency_units: !frequency_units, with the contact_id [!contact_id]',
        [
          "!frequency_unit" => $msg['frequency_unit'],
          "!frequency_units" => implode(', ', $frequency_units),
          "!contact_id" => $contact_id,
        ]
      );
      throw new WMFException(WMFException::IMPORT_SUBSCRIPTION, $error_message);
    }

    // Frequency interval is only allowed to be 1. FIXME
    if ($msg['frequency_interval'] !== 1) {
      $error_message = t(
        '`frequency_interval` is only allowed to be set to 1, with the contact_id [!contact_id]',
        ["!contact_id" => $contact_id]
      );
      throw new WMFException(WMFException::IMPORT_SUBSCRIPTION, $error_message);
    }

    // Installments is only allowed to be 0.
    if ($msg['installments'] !== 0) {
      $error_message = t(
        '`installments` must be set to 0, with the contact_id [!contact_id]',
        ["!contact_id" => $contact_id]
      );
      throw new WMFException(WMFException::IMPORT_SUBSCRIPTION, $error_message);
    }

    // For Gravy PayPal transactions, use the recurring_payment_token as the subscription ID
    // instead of the gateway_txn_id. This is a workaround as Gravy only sends us the
    // recurring_payment_token in related webhooks so to allow us to match them up we use the
    // same ID as the civicrm_contribution_recur.trxn_id. See T399868
    if ($message->isGravyPaypal() && !empty($msg['recurring_payment_token'])) {
      $gateway_subscr_id = $msg['recurring_payment_token'];
    }
    elseif ($message->getSubscriptionID()) {
      $gateway_subscr_id = $message->getSubscriptionID();
    }
    elseif (!empty($msg['gateway_txn_id'])) {
      // @todo - do we need this? getSubscriptionID() already looks in here
      // it it is Amazon - if it could be valid for other
      // processors too then maybe we need to fix there.
      $gateway_subscr_id = $msg['gateway_txn_id'];
    }
    else {
      $error_message = t(
        '`trxn_id` must be set and not empty, with the contact_id [!contact_id]',
        ["!contact_id" => $contact_id]
      );
      throw new WMFException(WMFException::IMPORT_SUBSCRIPTION, $error_message);
    }

    $msg['cycle_day'] = (int) (gmdate('j', $msg['date']));

    $next_sched_contribution = \CRM_Core_Payment_Scheduler::getNextContributionDate($msg);
    try {
      if (!empty($msg['payment_processor_id']) && !empty($msg['payment_token_id'])) {
        // copy existing payment token and processor IDs from message
        // @todo - it's not really clear if this is reachable - the call that
        // set payment_token_id is now in the follow-on else-if and
        // I suspect that it is never passed in & hence this is never hit.
        $extra_recurring_params = [
          'payment_token_id' => $msg['payment_token_id'],
          'payment_processor_id' => $msg['payment_processor_id'],
          'processor_id' => $gateway_subscr_id,
        ];
      }
      elseif (!empty($msg['recurring_payment_token']) && !$message->getPaymentTokenID()) {
        // When there is a token on the $msg but not in the db
        // Create recurring token if it isn't already there
        $payment_token_result = PaymentToken::create(FALSE)
          ->setValues([
            'contact_id' => $contact_id,
            'payment_processor_id.name' => $message->getGateway(),
            'token' => $msg['recurring_payment_token'],
            'ip_address' => $msg['user_ip'] ?? NULL,
          ]
          )->execute()->first();
        // Audit files bring in recurrings that we have the token for but were never created
        \Civi::log('wmf')->info('queue2civicrm_import: No payment token found. Creating : {token}', ['token' => $payment_token_result['id']]);
        $extra_recurring_params = [
          'payment_token_id' => $payment_token_result['id'],
          'payment_processor_id' => $payment_token_result['payment_processor_id'],
          'processor_id' => $gateway_subscr_id,
        ];
      }
      elseif (PaymentProcessorHelper::getPaymentProcessorID($msg['gateway'])) {
        $extra_recurring_params = [
          'payment_processor_id' => PaymentProcessorHelper::getPaymentProcessorID($msg['gateway']),
          'processor_id' => $gateway_subscr_id,
        ];
      }
      else {
        // Old-style recurring, initialize processor_id to 1 for use as effort ID
        $extra_recurring_params = [
          'processor_id' => 1,
        ];
      }

      // Using custom field to hold the processor_contact_id for Adyen.
      if (!empty($msg['processor_contact_id'])) {
        $extra_recurring_params['contribution_recur_smashpig.processor_contact_id'] = $msg['processor_contact_id'];
      }

      if (!empty($msg['initial_scheme_transaction_id'])) {
        $extra_recurring_params['contribution_recur_smashpig.initial_scheme_transaction_id'] = $msg['initial_scheme_transaction_id'];
      }

      if (!empty($msg['country'])) {
        $extra_recurring_params['contribution_recur_smashpig.original_country:abbr'] = $msg['country'];
      }

      $insert_params = [
        'payment_instrument_id' => $msg['payment_instrument_id'],
        'contact_id' => $contact_id,
        'amount' => $msg['original_gross'],
        'currency' => $msg['original_currency'],
        'financial_type_id:name' => 'Cash',
        'frequency_unit' => $msg['frequency_unit'],
        'frequency_interval' => $msg['frequency_interval'],
        'installments' => $msg['installments'],
        'start_date' => $message->getDate(),
        'create_date' => $message->getDate(),
        'cancel_date' => $message->getCancelDate(),
        'cycle_day' => $msg['cycle_day'],
        'next_sched_contribution_date' => $next_sched_contribution,
        'trxn_id' => $gateway_subscr_id,
        'contribution_status_id:name' => 'Pending',
      ] + $extra_recurring_params;

      $recur = ContributionRecur::create(FALSE)
        ->setValues($insert_params)
        ->execute()
        ->first();
      $message->setContributionRecurID($recur['id']);
    }
    catch (\CRM_Core_Exception $e) {
      throw new WMFException(WMFException::IMPORT_SUBSCRIPTION, $e->getMessage());
    }
  }
}

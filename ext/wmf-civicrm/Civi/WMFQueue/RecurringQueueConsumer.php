<?php

namespace Civi\WMFQueue;

use Civi;
use Civi\Api4\Address;
use Civi\Api4\Contact;
use Civi\Api4\Email;
use Civi\Api4\PaymentToken;
use Civi\Api4\ThankYou;
use Civi\Api4\WMFContact;
use Civi\Core\Exception\DBQueryException;
use Civi\Helper\FailureEmail;
use Civi\WMFHelper\ContributionRecur as RecurHelper;
use Civi\Api4\ContributionRecur;
use Civi\Api4\ContributionTracking;
use Civi\WMFException\WMFException;
use Civi\WMFHelper\PaymentProcessor;
use Civi\WMFQueueMessage\RecurDonationMessage;
use SmashPig\Core\DataStores\QueueWrapper;
use Civi\WMFTransaction;

class RecurringQueueConsumer extends TransactionalQueueConsumer {

  /**
   * Import messages about recurring payments
   *
   * @param array $message
   *
   * @throws WMFException
   * @throws \CRM_Core_Exception
   * @throws StatisticsCollectorException
   */
  public function processMessage($message) {
    if (empty($message['txn_type']) || !in_array($message['txn_type'], [
      // subscription canceled by user at the gateway.
      'subscr_cancel',
      // subscription expired (end of term)
      'subscr_eot',
      // failed signup
      'subscr_failed',
      // 'subscr_modify' - we don't handle subscription modifications here.
      // subscription account creation
      'subscr_signup',
      // subscription payment
      'subscr_payment',
    ])) {
      throw new WMFException(WMFException::INVALID_RECURRING, 'Msg not recognized as a recurring payment related message.');
    }
    // Set recurring to true in case the message is re-queued.
    // @todo - we can switch later to do this at the point where we re-queue.
    $message['recurring'] = TRUE;
    $messageObject = new RecurDonationMessage($message);
    $messageObject->validate();
    $message = $messageObject->normalize();
    $skipContributionTracking = $messageObject->isAmazon() || $messageObject->isAutoRescue();

    if (!$skipContributionTracking && !$messageObject->getContributionTrackingID()) {
      $message['contribution_tracking_id'] = $this->getContributionTracking($message);
    }

    // route the message to the appropriate handler depending on transaction type
    if ($messageObject->isPayment()) {
      $this->importSubscriptionPayment($messageObject, $message);
    }
    else {
      $this->importSubscriptionAccount($messageObject, $message);
    }
  }

  /**
   * Import a recurring payment from PayPal.
   *
   * Other recurring payments go directly into donation queue.
   * PayPal is different. This is largely historical but there is a real
   * reason in that currently this code is what ensures the ContributionRecur
   * record exists. Potentially the Donation queue could do that too.
   *
   * @param \Civi\WMFQueueMessage\RecurDonationMessage $message
   * @param array $msg
   *
   * @throws \CRM_Core_Exception
   * @throws \Civi\WMFException\WMFException
   * @throws StatisticsCollectorException
   */
  protected function importSubscriptionPayment(RecurDonationMessage $message, $msg) {
    /**
     * if the subscr_id is not set, we can't process it due to an error in the message.
     *
     * otherwise, check for the parent record in civicrm_contribution_recur.
     * if one does not exist, the message is not ready for reprocessing, so requeue it.
     *
     * otherwise, process the payment.
     */
    if (!$message->getSubscriptionID()) {
      // @todo - didn't the validate in the previous function pick up these already?
      throw new WMFException(WMFException::INVALID_RECURRING, 'Msg missing the subscr_id; cannot process.');
    }

    if ($message->getContributionRecurID() && RecurHelper::gatewayManagesOwnRecurringSchedule($msg['gateway'])) {
      // If parent record is mistakenly marked as Completed, Cancelled, or Failed, reactivate it
      // @todo - confirm this duplicates the processing that happens once this
      // is pushed to the donation queue & remove from here.
      RecurHelper::reactivateIfInactive([
        'id' => $message->getContributionRecurID(),
        'contribution_status_id' => $message->getExistingContributionRecurValue('contribution_status_id'),
      ]);
    }

    // Since October 2018 or so, PayPal has been doing two things that really
    // mess with us.
    // 1) Sending mass cancellations for old-style subscriptions (i.e. ones we
    //    record with gateway=paypal and trxn_id LIKE S-%)
    // 2) Sending payment messages for those subscriptions with new-style
    //    subscr_ids (I-%, which we associate with paypal_ec), without first
    //    sending us notice that a new subscription is starting.
    // This next conditional tries to associate a paypal* message that has a
    // new-style subscr_id which isn't found in the contribution_recur table,
    // by associating it with an existing (possible canceled) old-style PayPal
    // recurring donation for the same email address. If PayPal gives us better
    // advice on how to deal with their ID migration, delete this.
    if (
      !$message->getContributionRecurID() &&
      !empty($msg['email']) &&
      $message->isPaypal() &&
      strpos($msg['subscr_id'], 'I-') === 0
    ) {
      Civi::log('wmf')->info('Creating new contribution_recur record while processing a subscr_payment');
      // PayPal has just not been sending subscr_signup messages for a lot of
      // messages lately. Insert a whole new contribution_recur record.
      $startMessage = [
        'txn_type' => 'subscr_signup',
      ] + $msg;
      $this->importSubscriptionSignup($message, $startMessage);
    }
    if (!$message->getContributionRecurID()) {
      Civi::log('wmf')->notice('recurring: Msg does not have a matching recurring record in civicrm_contribution_recur; requeueing for future processing.');
      throw new WMFException(WMFException::MISSING_PREDECESSOR, "Missing the initial recurring record for subscr_id {$msg['subscr_id']}");
    }

    $msg['contact_id'] = $message->getExistingContributionRecurValue('contact_id');
    $msg['contribution_recur_id'] = $message->getContributionRecurID();

    // Unset email & address values if the contact already has details.
    // Do separate 'quick' look-ups as likely to be less lock-inducing.
    $emailExists = Email::get(FALSE)
      ->addWhere('contact_id', '=', $msg['contact_id'])
      ->addSelect('email')
      ->execute()->first()['email'] ?? FALSE;
    if ($emailExists) {
      // If the contact already has an email then do not attempt to update or override it
      // because this flow is only used by PayPal, which is not considered a more reliable source.
      unset($msg['email']);
    }

    $address = Address::get(FALSE)
      ->addSelect('country_id:name', '*')
      ->addWhere('contact_id', '=', $msg['contact_id'])
      ->execute()->first();
    $isUpdateAddress = $address === NULL;
    if ($address && empty($address['street_address']) && !empty($msg['street_address'])) {
      // We might have something to add here.... as long as the country is the same.
      if ($msg['country'] === $address['country_id:name']) {
        $isUpdateAddress = TRUE;
      }
    }
    if (!$isUpdateAddress) {
      // Unsetting country is actually enough to block it. The rest are for clarity.
      unset($msg['street_address'], $msg['country'], $msg['city'], $msg['postal_code'], $msg['state_province']);
    }

    if (!RecurHelper::isFirst($message->getContributionRecurID())) {
      // @todo - confirm this is unnecessary as done in the Donations queue & remove.
      $msg['financial_type_id'] = RecurHelper::getFinancialTypeForSubsequentContributions();
    }
    QueueWrapper::push('donations', $msg);
  }

  /**
   * Import subscription account
   *
   * Routes different subscription message types to an appropriate handling
   * function.
   *
   * @param array $msg
   *
   * @throws \Civi\WMFException\WMFException
   */
  protected function importSubscriptionAccount(RecurDonationMessage $message, $msg) {
    switch ($msg['txn_type']) {
      case 'subscr_signup':
        $this->importSubscriptionSignup($message, $msg);
        break;

      case 'subscr_cancel':
        $this->importSubscriptionCancel($message);
        break;

      case 'subscr_eot':
        $this->importSubscriptionExpired($msg);
        break;

      case 'subscr_modify':
        throw new \CRM_Core_Exception('unexpected subscr_modify message');

      case 'subscr_failed':
        $this->importSubscriptionPaymentFailed($message, $msg);
        break;

      default:
        throw new WMFException(WMFException::INVALID_RECURRING, 'Invalid subscription message type');
    }
  }

  /**
   * Get the contribution tracking id for a given a recurring trxn
   *
   * If the 'custom' field is not set (from paypal, which would normally carry the tracking id),
   * we look and see if any related recurring transactions have had a contrib tracking id set.
   *
   * If they do, we'll use that contrib tracking id, otherwise we'll generate a new row in the
   * contrib tracking table.
   *
   * @param array $msg
   *
   * @return int contribution tracking id
   */
  private function getContributionTracking($msg) {
    if ($msg['txn_type'] == 'subscr_payment') {
      $queryResult = ContributionRecur::get(FALSE)
        ->addSelect('MIN(contribution_tracking.id) AS ctid', 'MIN(contribution.id) AS contribution_id')
        ->addJoin('Contribution AS contribution', 'INNER')
        ->addJoin('ContributionTracking AS contribution_tracking', 'LEFT', ['contribution_tracking.contribution_id', '=', 'contribution.id'])
        ->addGroupBy('id')
        ->addWhere('trxn_id', '=', $msg['subscr_id'])
        ->setLimit(1)
        ->execute()
        ->first();
      $contribution_tracking_id = $queryResult['ctid'] ?? NULL;
      $contribution_id = $queryResult['contribution_id'] ?? NULL;

      if (!empty($contribution_tracking_id)) {
        \Civi::log('wmf')->debug(
          'recurring: recurring_get_contribution_tracking_id: Selected contribution tracking id from past contributions, {contribution_tracking_id}',
          ['contribution_tracking_id' => $contribution_tracking_id]
        );
      }
      // if we still don't have a contribution tracking id (but we do have previous contributions),
      // we're gonna have to add new contribution tracking.
      if ($contribution_id && !$contribution_tracking_id) {
        $tracking = [
          'utm_medium' => 'civicrm',
          'tracking_date' => empty($msg['payment_date']) ? $msg['date'] : $msg['payment_date'],
          'contribution_id' => $contribution_id,
        ];
        $contribution_tracking_id = $this->generateContributionTracking($tracking);
        \Civi::log('wmf')->debug('recurring: recurring_get_contribution_tracking_id: Got new contribution tracking id, {contribution_tracking_id}', ['contribution_tracking_id' => $contribution_tracking_id]);
      }
      return $contribution_tracking_id;
    }
    else {
      \Civi::log('wmf')->debug('recurring: recurring_get_contribution_tracking_id: No contribution_tracking_id returned.');
      return NULL;
    }
  }

  /**
   * Import a subscription signup message
   *
   * @param \Civi\WMFQueueMessage\RecurDonationMessage $message
   * @param array $msg
   *
   * @throws \CRM_Core_Exception
   * @throws \Civi\API\Exception\UnauthorizedException
   * @throws \Civi\WMFException\WMFException
   */
  protected function importSubscriptionSignup(RecurDonationMessage $message, array $msg): void {
    $contact = NULL;
    // ensure there is not already a record of this account - if so, mark the message as succesfuly processed
    if (!empty($msg['contribution_recur_id'])) {
      throw new WMFException(WMFException::DUPLICATE_CONTRIBUTION, 'Subscription account already exists');
    }
    $ctRecord = ContributionTracking::get(FALSE)
      ->addWhere('id', '=', $msg['contribution_tracking_id'])
      ->execute()
      ->first();
    try {
      if (empty($ctRecord['contribution_id'])) {
        // create contact record
        $contact = WMFContact::save(FALSE)
          ->setMessage($msg)
          ->execute()->first();
        $contactId = $contact['id'];
      }
      else {
        $contactId = civicrm_api3('Contribution', 'getvalue', [
          'id' => $ctRecord['contribution_id'],
          'return' => 'contact_id',
        ]);

        if (isset($msg['legal_identifier'])) {
          Contact::update(FALSE)
            ->addWhere('id', '=', $contactId)
            ->addValue('legal_identifier', $msg['legal_identifier'])
            ->execute();
        }
      }

      $cycle_day = !empty($message->getStartDate()) ? date('j', strtotime($message->getStartDate())) : NULL;
      $params = [
        'contact_id' => $contactId,
        'currency' => $msg['original_currency'],
        'amount' => $msg['original_gross'],
        // If not provided (eg. a payment where we missed the signup) we assume monthly.
        'frequency_unit' => $msg['frequency_unit'] ?? 'month',
        'frequency_interval' => $msg['frequency_interval'] ?? 1,
        'payment_instrument_id' => $msg['payment_instrument_id'] ?? NULL,
        // Set installments to 0 - they should all be open ended
        'installments' => 0,
        'start_date' => $message->getStartDate(),
        'create_date' => $message->getCreateDate(),
        'trxn_id' => $msg['subscr_id'],
        'financial_type_id:name' => 'Cash',
        'next_sched_contribution_date' => $message->getStartDate(),
        'cycle_day' => $cycle_day,
      ];
      if (PaymentProcessor::getPaymentProcessorID($msg['gateway'])) {
        // We could pass the gateway name to the api for resolution but it would reject
        // any gateway values with no valid processor mapping so we do this ourselves.
        $params['payment_processor_id'] = PaymentProcessor::getPaymentProcessorID($msg['gateway']);
      }
      // Create a new recurring donation with a token
      if (isset($msg['recurring_payment_token'])) {
        // Check that the original contribution has processed first
        if (empty($ctRecord['contribution_id'])) {
          throw new WMFException(WMFException::MISSING_PREDECESSOR, 'Recurring queue processed before donations queue');
        }

        // Create a token
        $params['payment_token_id'] = PaymentToken::create(FALSE)
          ->setValues([
            'contact_id' => $contactId,
            'payment_processor_id.name' => $msg['gateway'],
            'token' => $msg['recurring_payment_token'],
            'ip_address' => $msg['user_ip'],
          ]
          )->execute()->first()['id'];

        // Create a non paypal style trxn_id
        $params['trxn_id'] = WMFTransaction::from_message($msg)
          ->get_unique_id();
        $params['processor_id'] = $msg['gateway_txn_id'];
        $params['invoice_id'] = $msg['order_id'];
      }

      if (isset($msg['initial_scheme_transaction_id'])) {
        $params['contribution_recur_smashpig.initial_scheme_transaction_id'] = $msg['initial_scheme_transaction_id'];
      }

      if (isset($msg['processor_contact_id'])) {
        $params['contribution_recur_smashpig.processor_contact_id'] = $msg['processor_contact_id'];
      }

      if (isset($msg['rescue_reference'])) {
        $params['contribution_recur_smashpig.rescue_reference'] = $msg['rescue_reference'];
      }

      if (!empty($msg['country'])) {
        $params['contribution_recur_smashpig.original_country:abbr'] = $msg['country'];
      }

      $newContributionRecur = $this->createContributionRecurWithErrorHandling($params);
      $message->setContributionRecurID($newContributionRecur['id']);
    }
    catch (\CRM_Core_Exception $e) {
      throw new WMFException(WMFException::IMPORT_CONTRIB, 'Failed inserting subscriber signup for subscriber id: ' . print_r($msg['subscr_id'], TRUE) . ': ' . $e->getMessage());
    }

    $this->sendSuccessThankYouMail($newContributionRecur, $ctRecord, $msg, $contactId, $contact);
    Civi::log('wmf')->notice('recurring: Successfully inserted subscription signup for subscriber id: {subscriber_id}', ['subscriber_id' => $msg['subscr_id']]);
  }

  protected function createContributionRecurWithErrorHandling($params) {
    try {
      return $this->createContributionRecur($params);
    }
    // Ideally we would move this catching higher up
    // see https://phabricator.wikimedia.org/T365418.
    catch (DBQueryException $e) {
      if (in_array($e->getDBErrorMessage(), ['constraint violation', 'deadlock', 'database lock timeout'], TRUE)) {
        throw new WMFException(WMFException::DATABASE_CONTENTION, 'Contribution not saved due to database load', $e->getErrorData());
      }
      // Could be a mysql error so let's pass it on up
      throw $e;
    }
  }

  protected function updateContributionRecurWithErrorHandling($params) {
    try {
      return $this->updateContributionRecur($params);
    }
    catch (DBQueryException $e) {
      if (in_array($e->getDBErrorMessage(), ['constraint violation', 'deadlock', 'database lock timeout'], TRUE)) {
        throw new WMFException(WMFException::DATABASE_CONTENTION, 'Contribution not saved due to database load', $e->getErrorData());
      }
      // If it is a mysql query error then re-throw it.
      throw $e;
    }
  }

  /**
   * @param array $params
   *
   * @return array|null
   * @throws \CRM_Core_Exception
   * @throws \Civi\Core\Exception\DBQueryException
   */
  protected function createContributionRecur(array $params): ?array {
    return ContributionRecur::create(FALSE)
      ->setValues($params)
      ->execute()
      ->first();
  }

  /**
   * @throws \Civi\Core\Exception\DBQueryException
   * @throws \CRM_Core_Exception
   */
  protected function updateContributionRecur(array $params): ?array {
    return ContributionRecur::update(FALSE)
      ->setValues($params)
      ->execute()
      ->first();
  }

  protected function sendSuccessThankYouMail($contributionRecur, $ctRecord, $msg, $contactId, $contact) {
    // Send an email that the recurring donation has been created
    // @todo - it' not clear that there is any benefit in loading the contact
    // here as it will be loaded in the ThankYou.send if not present.
    if (isset($msg['recurring_payment_token']) && isset($contributionRecur['id'])) {
      // Get the contact information if not already there
      if (empty($contact)) {
        $contact = civicrm_api3('Contact', 'getsingle', ['id' => $contactId]);
      }
      else {
        $contact = $contact['values'][$contactId];
        $contact['email'] = $msg['email'];
      }

      // Set up the language for the email
      $locale = $contact['preferred_language'];
      if (!$locale) {
        Civi::log('wmf')->info('monthly_convert: Donor language unknown.  Defaulting to English...');
        $locale = 'en';
      }
      $locale = strtolower(substr($locale, 0, 2));

      // Using the same params sent through in thank_you.module thank_you_for_contribution
      $start_date = $contributionRecur['start_date'];

      // Get the day of the month
      $day_of_month = \DateTime::createFromFormat('YmdHis', $start_date, new \DateTimeZone('UTC'))
        ->format('j');

      // Format the day of month
      // TODO: This should probably be in the TwigLocalization logic
      $ordinal = new \NumberFormatter($locale, \NumberFormatter::ORDINAL);
      $day_of_month = $ordinal->format($day_of_month);

      $params = [
        'amount' => $msg['original_gross'],
        'contact_id' => $contactId,
        'currency' => $msg['original_currency'],
        'first_name' => $contact['first_name'],
        'last_name' => $contact['last_name'],
        // Locale is the mediawiki variant - either 'en' or 'en-US'.
        // Where 'preferred_language' is known then locale should generally
        // be ignored, except where required for constructing urls.
        'locale' => $locale,
        // Preferred language is as stored in the civicrm database - eg. 'en_US'.
        'language' => $contact['preferred_language'],
        'receive_date' => $start_date,
        'day_of_month' => $day_of_month,
        'recipient_address' => $contact['email'],
        'recurring' => TRUE,
        'transaction_id' => "CNTCT-{$contactId}",
        // shown in the body of the text
        'contribution_id' => $ctRecord['contribution_id'],
      ];

      $success = ThankYou::send(FALSE)
        ->setDisplayName($contact['display_name'])
        ->setLanguage($params['language'])
        ->setTemplateName('monthly_convert')
        ->setContributionID($ctRecord['contribution_id'])
        ->setParameters($params)
        ->setActivityType('Recurring convert email')
        ->execute()->first()['is_success'];

      $context = [
        'contribution_recur_id' => $contributionRecur['id'],
        'recipient_address' => $params['recipient_address'],
      ];
      if ($success) {
        Civi::log('wmf')->info('monthly_convert: Monthly convert sent successfully for recurring contribution id: {contribution_recur_id} to {recipient_address}', $context);
      }
      else {
        Civi::log('wmf')->error('monthly_convert: Monthly convert mail failed for recurring contribution id: {contribution_recur_id} to {recipient_address}', $context);
      }
    }
  }

  /**
   * Process a subscriber cancellation
   *
   * @param \Civi\WMFQueueMessage\RecurDonationMessage $message
   *
   * @throws WMFException
   */
  protected function importSubscriptionCancel(RecurDonationMessage $message) {
    if (empty($message->getContributionRecurID())) {
      // PayPal has recently been sending lots of invalid cancel and fail notifications
      // Revert this patch when that's resolved
      return;
      // throw new WMFException(WMFException::INVALID_RECURRING, 'Subscription account does not exist');
    }

    try {
      $date = $message->getCancelDate() ?? date('Ymd H:i:s');
      $update_params = [
        'id' => $message->getContributionRecurID(),
        'cancel_date' => $date,
        'cancel_reason' => $message->getCancelReason() ?? '(auto) User Cancelled via Gateway',
        'contribution_status_id:name' => 'Cancelled',
        'end_date' => $date,
      ];
      if ($message->isAutoRescue()) {
        // If the payment processor has told us they gave up on the auto-rescue attempt, we should
        // clear out the rescue_reference so we don't try to send them another auto-rescue cancel
        // API call (see civicrm_post hook in WMFHelper\ContributionRecur::cancelRecurAutoRescue)
        $update_params['contribution_recur_smashpig.rescue_reference'] = '';

        if (!$this->hasOtherActiveRecurringContribution($message->getContactID(), $message->getContributionRecurID())) {
          // The failure email is otherwise sent from the recurring smashpig extension. When an autorescue
          // attempt ends in failure, we cancel here and need to send the email as well
          // Note: We only send the email if the contact does not have any other active recurring contributions
          FailureEmail::sendViaQueue($message->getContactID(), $message->getContributionRecurID());
        }
      }
      $this->updateContributionRecurWithErrorHandling($update_params);

      // Since the API4 update doesn't create the activity the api3 cancel call does, we create it here
      // Details a bit more vague and both contact IDs are the donor, but the rest should be the same.
      // Based on code in CRM_Contribute_BAO_ContributionRecur::cancelRecurContribution
      Civi\Api4\Activity::create(FALSE)
        ->addValue('date', $date)
        ->addValue('activity_type_id:name', 'Cancel Recurring Contribution')
        ->addValue('details', 'Recurring subscription was cancelled in the RecurringQueueConsumer')
        ->addValue('status_id:name', 'Completed')
        ->addValue('subject', 'Recurring contribution cancelled')
        ->addValue('source_contact_id', $message->getContactID())
        ->addValue('target_contact_id', $message->getContactID())
        ->addValue('source_record_id', $message->getContributionRecurID())
        ->execute();
    }
    catch (\CRM_Core_Exception $e) {
      throw new WMFException(WMFException::INVALID_RECURRING, 'There was a problem cancelling contribution recur ID: ' . $message->getContributionRecurID());
    }
    Civi::log('wmf')->notice('recurring: Successfully cancelled contribution recur id {contributionRecurId}', ['contributionRecurId' => $message->getContributionRecurID()]);
  }

  /**
   * Process an expired subscription.
   *
   * Based on the reviewing the resulting records we can see that no
   * recurrings have the status (auto) Expiration notification without having a
   * cancel_date. In each case the cancel_date precedes the end date - it seems
   * that we receive this notification from paypal after some other type of
   * cancellation has already been received. I WAS going to suggest we ALSO set
   * cancel_date in this call but that seems unnecessary given the 100% overlap
   * seemingly with already cancelled recurrings.
   *
   * @param array $msg
   *
   * @throws \Civi\WMFException\WMFException
   */
  protected function importSubscriptionExpired($msg) {
    // ensure we have a record of the subscription
    if (empty($msg['contribution_recur_id'])) {
      // PayPal has recently been sending lots of invalid cancel and fail notifications
      // Revert this patch when that's resolved
      return;
      // throw new WMFException(WMFException::INVALID_RECURRING, 'Subscription account does not exist');
    }

    try {
      // See function comment block for discussion.
      $params = [
        'id' => $msg['contribution_recur_id'],
        'end_date' => 'now',
        'contribution_status_id:name' => 'Completed',
        'cancel_reason' => '(auto) Expiration notification',
        'next_sched_contribution_date' => NULL,
        'failure_retry_date' => NULL,
      ];

      $this->updateContributionRecurWithErrorHandling($params);
    }
    catch (\CRM_Core_Exception $e) {
      throw new WMFException(WMFException::INVALID_RECURRING, 'There was a problem updating the subscription for EOT for subscription id: %subscr_id' . print_r($msg['subscr_id'], TRUE) . ": " . $e->getMessage());
    }
    Civi::log('wmf')->notice('recurring: Successfully ended subscription for subscriber id: {subscriber_id}', ['subscriber_id' => $msg['subscr_id']]);
  }

  /**
   * Process failed subscription payment
   *
   * @param \Civi\WMFQueueMessage\RecurDonationMessage $message
   * @param array $msg
   *
   * @throws \CRM_Core_Exception
   * @throws \Civi\WMFException\WMFException
   * @throws \DateMalformedStringException
   */
  protected function importSubscriptionPaymentFailed(RecurDonationMessage $message, array $msg): void {
    // ensure we have a record of the subscription
    if (empty($msg['contribution_recur_id'])) {
      // PayPal has recently been sending lots of invalid cancel and fail notifications
      // Revert this patch when that's resolved
      return;
      // throw new WMFException(WMFException::INVALID_RECURRING, 'Subscription account does not exist for subscription id: ' . print_r($msg['subscr_id'], TRUE));
    }

    $updateParams = [
      'failure_count' => $msg['failure_count'] + 1,
    ];

    $currentStatus = ContributionRecur::get(FALSE)
      ->addWhere('id', '=', $msg['contribution_recur_id'])
      ->addSelect('contribution_status_id:name')
      ->execute()->first()['contribution_status_id:name'];

    if ($currentStatus === 'Cancelled') {
      Civi::log('wmf')->notice(
        'Skipped recorded failed payment for subscription id: {subscriber_id} on an already-cancelled subscription',
        ['subscriber_id' => $msg['subscr_id']]
      );
      return;
    }

    if (in_array($currentStatus, ['In Progress', 'Pending', 'Processing'])) {
      $updateParams['contribution_status_id:name'] = 'Failing';
    }

    if ($message->getFailureRetryDate()) {
      $updateParams['failure_retry_date'] = $message->getFailureRetryDate();
    }

    try {
      ContributionRecur::update(FALSE)
        ->addWhere('id', '=', $msg['contribution_recur_id'])
        ->setValues($updateParams)->execute();
    }
    catch (DBQueryException $e) {
      if (in_array($e->getDBErrorMessage(), ['constraint violation', 'deadlock', 'database lock timeout'], TRUE)) {
        throw new WMFException(WMFException::DATABASE_CONTENTION, 'Contribution not saved due to database load', $e->getErrorData());
      }
      throw $e;
    }
    catch (\CRM_Core_Exception $e) {
      throw new WMFException(WMFException::INVALID_RECURRING, 'There was a problem updating the subscription for failed payment for subscriber id: ' . print_r($msg['subscr_id'], TRUE) . ": " . $e->getMessage());
    }
    Civi::log('wmf')->notice('recurring: Successfully recorded failed payment for subscriber id: {subscriber_id} ', ['subscriber_id' => print_r($msg['subscr_id'], TRUE)]);
  }

  /**
   * Check if the donor has another active recurring contribution set up.
   *
   * @todo: I took this from CRM_Core_Payment_SmashPigRecurringProcessor so we
   * can probably extract this to a common place and use it in both places.
   *
   * @param int $contactID
   * @param int $recurringID ID of recurring contribution record
   *
   * @return bool
   * @throws \CRM_Core_Exception
   */
  protected function hasOtherActiveRecurringContribution(int $contactID, int $recurringID): bool {
    $recurringCount = ContributionRecur::get(FALSE)
      ->addWhere('id', '!=', $recurringID)
      ->addWhere('contact_id', '=', $contactID)
      ->addWhere('contribution_status_id:name', 'IN', [
        'Pending',
        'Overdue',
        'In Progress',
        'Failing',
      ])
      ->addWhere('payment_token_id', 'IS NOT NULL')
      ->execute()
      ->count();

    return $recurringCount > 0;
  }

}

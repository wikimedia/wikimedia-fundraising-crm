<?php

namespace Civi\WMFQueue;

use Civi;
use Civi\Api4\Address;
use Civi\Api4\Contact;
use Civi\Api4\Email;
use Civi\Api4\WMFContact;
use Civi\Core\Exception\DBQueryException;
use Civi\WMFHelper\ContributionRecur as RecurHelper;
use Civi\Api4\ContributionRecur;
use Civi\WMFException\WMFException;
use Civi\WMFHelper\PaymentProcessor;
use Civi\WMFQueueMessage\RecurDonationMessage;
use SmashPig\Core\DataStores\QueueWrapper;
use Civi\WMFTransaction;
use Statistics\Exception\StatisticsCollectorException;

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
      if (wmf_civicrm_get_contributions_from_gateway_id($message['gateway'], $message['gateway_txn_id'])) {
        Civi::log('wmf')->notice('recurring: Duplicate contribution: {gateway}-{gateway_txn_id}.', [
          'gateway' => $message['gateway'],
          'gateway_txn_id' => $message['gateway_txn_id'],
        ]);
        throw new WMFException(WMFException::DUPLICATE_CONTRIBUTION, "Contribution already exists. Ignoring message.");
      }
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
   * @param RecurDonationMessage $message
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
      throw new WMFException(WMFException::INVALID_RECURRING, 'Msg missing the subscr_id; cannot process.');
    }
    // check for parent record in civicrm_contribution_recur and fetch its id
    $recur_record = wmf_civicrm_get_gateway_subscription($msg['gateway'], $msg['subscr_id']);

    if ($recur_record && RecurHelper::gatewayManagesOwnRecurringSchedule($msg['gateway'])) {
      // If parent record is mistakenly marked as Completed, Cancelled, or Failed, reactivate it
      RecurHelper::reactivateIfInactive((array) $recur_record);
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
      !$recur_record &&
      !empty($msg['email']) &&
      $message->isPaypal() &&
      strpos($msg['subscr_id'], 'I-') === 0
    ) {
      $recur_record = wmf_civicrm_get_legacy_paypal_subscription($msg);
      if ($recur_record) {
        Civi::log('wmf')->info('Updating legacy paypal contribution recur row');
        // We found an existing legacy PayPal recurring record for the email.
        // Update it to make sure it's not mistakenly canceled, and while we're
        // at it, stash the new subscr_id in unused field processor_id, in case
        // we need it later.
        ContributionRecur::update(FALSE)
          ->addWhere('id', '=', $recur_record->id)
          ->setValues([
            'contribution_status_id.name' => 'In Progress',
            'cancel_date' => NULL,
            'end_date' => NULL,
            'processor_id' => $msg['subscr_id']
          ])->execute();
        // Make the message look like it should be associated with that record.
        // There is some code in wmf_civicrm_contribution_message_import that
        // might look the recur record up again (FIXME, but not now). This
        // mutation here will make sure the payment doesn't create a second
        // recurring record.
        $msg['subscr_id'] = $recur_record->id;
        $msg['gateway'] = 'paypal';
      }
      else {
        Civi::log('wmf')->info('Creating new contribution_recur record while processing a subscr_payment');
        // PayPal has just not been sending subscr_signup messages for a lot of
        // messages lately. Insert a whole new contribution_recur record.
        $startMessage = [
          'txn_type' => 'subscr_signup',
          ] + $msg;
        $this->importSubscriptionSignup($startMessage);
        $recur_record = wmf_civicrm_get_gateway_subscription($msg['gateway'], $msg['subscr_id']);
        if (!$recur_record) {
          Civi::log('wmf')->notice('recurring: Fallback contribution_recur record creation failed.');
          throw new WMFException(
            WMFException::IMPORT_SUBSCRIPTION,
            "Could not create the initial recurring record for subscr_id {$msg['subscr_id']}"
          );
        }
      }
    }
    if (!$recur_record) {
      Civi::log('wmf')->notice('recurring: Msg does not have a matching recurring record in civicrm_contribution_recur; requeueing for future processing.');
      throw new WMFException(WMFException::MISSING_PREDECESSOR, "Missing the initial recurring record for subscr_id {$msg['subscr_id']}");
    }

    $msg['contact_id'] = $recur_record->contact_id;
    $msg['contribution_recur_id'] = $recur_record->id;

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

    if (!RecurHelper::isFirst($recur_record->id)) {
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
        $this->importSubscriptionSignup($msg);
        break;

      case 'subscr_cancel':
        $this->importSubscriptionCancel($msg);
        break;

      case 'subscr_eot':
        $this->importSubscriptionExpired($msg);
        break;

      case 'subscr_modify':
        throw new \CRM_Core_Exception('unexpected subscr_modify message');

      case 'subscr_failed':
        $this->importSubscriptionPaymentFailed($msg);
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
        $rawDate = empty($msg['payment_date']) ? $msg['date'] : $msg['payment_date'];
        $date = wmf_common_date_unix_to_sql(strtotime($rawDate));
        $tracking = [
          'utm_source' => '..rpp', // FIXME: recurring donations are not all paypal
          'utm_medium' => 'civicrm',
          'ts' => $date,
          'contribution_id' => $contribution_id,
        ];
        $contribution_tracking_id = wmf_civicrm_insert_contribution_tracking($tracking);
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
   * @param array $msg
   */
  protected function importSubscriptionSignup($msg) {
    $contact = NULL;
    // ensure there is not already a record of this account - if so, mark the message as succesfuly processed
    if (!empty($msg['contribution_recur_id'])) {
      throw new WMFException(WMFException::DUPLICATE_CONTRIBUTION, 'Subscription account already exists');
    }
    $ctRecord = wmf_civicrm_get_contribution_tracking($msg);
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
        'start_date' => wmf_common_date_unix_to_civicrm($msg['start_date']),
        'create_date' => wmf_common_date_unix_to_civicrm($msg['create_date']),
        'trxn_id' => $msg['subscr_id'],
        'financial_type_id:name' => 'Cash',
        'next_sched_contribution_date' => wmf_common_date_unix_to_civicrm($msg['start_date']),
        'cycle_day' => date('j', strtotime(wmf_common_date_unix_to_civicrm($msg['start_date']))),
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
        $payment_token_result = wmf_civicrm_recur_payment_token_create(
          $contactId, $msg['gateway'], $msg['recurring_payment_token'], $msg['user_ip']
        );
        // Set up the params to have the token
        $params['payment_token_id'] = $payment_token_result['id'];
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

      $newContributionRecur = $this->createContributionRecurWithErrorHandling($params);
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
    catch (DBQueryException $e) {
      if (in_array($e->getDBErrorMessage(), ['constraint violation', 'deadlock', 'database lock timeout'], TRUE)) {
        throw new WMFException(WMFException::DATABASE_CONTENTION, 'Contribution not saved due to database load', $e->getErrorData());
      }
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
      $locale = wmf_common_locale_civi_to_mediawiki($locale);

      // Using the same params sent through in thank_you.module thank_you_for_contribution
      $template = 'monthly_convert';
      $start_date = $contributionRecur['start_date'];

      // Get the day of the month
      $day_of_month = \DateTime::createFromFormat('YmdHis', $start_date, new \DateTimeZone('UTC'))
        ->format('j');

      // Format the day of month
      // TODO: This should probably be in the TwigLocalization logic
      $ordinal = new \NumberFormatter($locale, \NumberFormatter::ORDINAL);
      $day_of_month = $ordinal->format($day_of_month);

      $params = [
        'template' => $template,
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
        'name' => $contact['display_name'],
        'receive_date' => $start_date,
        'day_of_month' => $day_of_month,
        'recipient_address' => $contact['email'],
        'recurring' => TRUE,
        'transaction_id' => "CNTCT-{$contactId}",
        // shown in the body of the text
        'contribution_id' => $ctRecord['contribution_id'],
      ];

      $success = thank_you_send_mail($params);
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
   * @param array $msg
   *
   * @throws \Civi\WMFException\WMFException
   */
  protected function importSubscriptionCancel($msg) {
    if (empty($msg['contribution_recur_id'])) {
      // PayPal has recently been sending lots of invalid cancel and fail notifications
      // Revert this patch when that's resolved
      return;
      // throw new WMFException(WMFException::INVALID_RECURRING, 'Subscription account does not exist');
    }

    try {
      $params = [
        'id' => $msg['contribution_recur_id'],
        // This line of code is only reachable if the txn type is 'subscr_cancel'
        // Which I believe always means the user has initiated the cancellation outside our process.
        'cancel_reason' => '(auto) User Cancelled via Gateway',
      ];

      if (!empty($msg['cancel_reason'])) {
        $params['cancel_reason'] = $msg['cancel_reason'];
      }
      civicrm_api3('ContributionRecur', 'cancel', $params);
    }
    catch (\CRM_Core_Exception $e) {
      throw new WMFException(WMFException::INVALID_RECURRING, 'There was a problem cancelling contribution recur ID: ' . $msg['contribution_recur_id']);
    }

    if ($msg['cancel_date']) {
      try {
        // Set cancel and end dates to match those from message.
        $update_params = [
          'id' => $msg['contribution_recur_id'],
          'cancel_date' => wmf_common_date_unix_to_civicrm($msg['cancel_date']),
          'end_date' => wmf_common_date_unix_to_civicrm($msg['cancel_date']),
        ];
        $this->updateContributionRecurWithErrorHandling($update_params);
      }
      catch (\CRM_Core_Exception $e) {
        throw new WMFException(WMFException::INVALID_RECURRING, 'There was a problem updating the cancellation for contribution recur ID: ' . $msg['contribution_recur_id'] . ": " . $e->getMessage());
      }
    }
    Civi::log('wmf')->notice('recurring: Successfully cancelled contribution recur id {contributionRecurId}', ['contributionRecurId' => $msg['contribution_recur_id']]);
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
    catch (\CiviCRM_API3_Exception $e) {
      throw new WMFException(WMFException::INVALID_RECURRING, 'There was a problem updating the subscription for EOT for subscription id: %subscr_id' . print_r($msg['subscr_id'], TRUE) . ": " . $e->getMessage());
    }
    Civi::log('wmf')->notice('recurring: Successfully ended subscription for subscriber id: {subscriber_id}', ['subscriber_id' => $msg['subscr_id']]);
  }

  /**
   * Process failed subscription payment
   *
   * @param array $msg
   *
   * @throws \Civi\WMFException\WMFException
   */
  protected function importSubscriptionPaymentFailed($msg) {
    // ensure we have a record of the subscription
    if (empty($msg['contribution_recur_id'])) {
      // PayPal has recently been sending lots of invalid cancel and fail notifications
      // Revert this patch when that's resolved
      return;
      // throw new WMFException(WMFException::INVALID_RECURRING, 'Subscription account does not exist for subscription id: ' . print_r($msg['subscr_id'], TRUE));
    }

    try {
      ContributionRecur::update(FALSE)
        ->addWhere('id', '=', $msg['contribution_recur_id'])
        ->setValues([
          'failure_count' => $msg['failure_count'] + 1,
          'failure_retry_date' => wmf_common_date_unix_to_civicrm($msg['failure_retry_date']),
        ])->execute();
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

}

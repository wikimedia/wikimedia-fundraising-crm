<?php

namespace Civi\WMFQueue;

use Civi;
use Civi\Api4\Action\WMFContact\Save;
use Civi\WMFHelper\ContributionRecur as RecurHelper;
use Civi\Api4\ContributionRecur;
use Civi\Api4\Activity;
use Civi\WMFException\WMFException;
use Civi\WMFHelper\PaymentProcessor;
use Civi\WMFQueueMessage\RecurDonationMessage;
use CRM_Core_Payment_Scheduler;

class RecurringQueueConsumer extends TransactionalQueueConsumer {

  public const RECURRING_UPGRADE_ACCEPT_ACTIVITY_TYPE_ID = 165;

  public const RECURRING_UPGRADE_DECLINE_ACTIVITY_TYPE_ID = 166;

  public const RECURRING_DOWNGRADE_ACTIVITY_TYPE_ID = 168;

  /**
   * Import messages about recurring payments
   *
   * @param array $message
   *
   * @throws \CRM_Core_Exception
   * @throws \Civi\WMFException\WMFException
   */
  public function processMessage($message) {
    $txn_upgrade_recur = ['recurring_upgrade', 'recurring_upgrade_decline', 'recurring_downgrade'];
    if (isset($message['txn_type']) && in_array($message['txn_type'], $txn_upgrade_recur, TRUE)) {
      $this->subscrModify($message);
      return;
    }

    if (!empty($message['is_successful_autorescue']) && $message['is_successful_autorescue'] === TRUE) {
      $recur_record = ContributionRecur::get(FALSE)
        ->addWhere('contribution_recur_smashpig.rescue_reference', '=', $message['rescue_reference'])
        ->addSelect('*', 'contribution.payment_instrument_id')
        ->addJoin('Contribution AS contribution', 'LEFT', ['contribution.contribution_recur_id', '=', 'id'])
        ->execute()
        ->first();
      if (!empty($recur_record)) {
        $message['payment_instrument_id'] = $recur_record['contribution.payment_instrument_id'];
        $message['contribution_recur_id'] = $recur_record['id'];
        $message['subscr_id'] = $recur_record['trxn_id'];
      }
      else {
        throw new WMFException(WMFException::INVALID_RECURRING, "Error finding rescued recurring payment with recurring reference {$msg['rescue_reference']}");
      }
    }

    $message = $this->normalizeMessage($message);

    // define the subscription txn type for an actual 'payment'
    $txn_subscr_payment = ['subscr_payment'];

    // define the subscription txn types that affect the subscription account
    $txn_subscr_acct = [
      'subscr_cancel', // subscription canceled by user at the gateway.
      'subscr_eot', // subscription expired
      'subscr_failed', // failed signup
      // 'subscr_modify', // subscription modification
      'subscr_signup', // subscription account creation
    ];

    // route the message to the appropriate handler depending on transaction type
    if (isset($message['txn_type']) && in_array($message['txn_type'], $txn_subscr_payment)) {
      if (wmf_civicrm_get_contributions_from_gateway_id($message['gateway'], $message['gateway_txn_id'])) {
        Civi::log('wmf')->notice('recurring: Duplicate contribution: {gateway}-{gateway_txn_id}.', [
          'gateway' => $message['gateway'],
          'gateway_txn_id' => $message['gateway_txn_id'],
        ]);
        throw new WMFException(WMFException::DUPLICATE_CONTRIBUTION, "Contribution already exists. Ignoring message.");
      }
      $this->importSubscriptionPayment($message);
    }
    elseif (isset($message['txn_type']) && in_array($message['txn_type'], $txn_subscr_acct)) {
      $this->importSubscriptionAccount($message);
    }
    else {
      throw new WMFException(WMFException::INVALID_RECURRING, 'Msg not recognized as a recurring payment related message.');
    }
  }

  /**
   * Convert queued message to a standardized format
   *
   * This is a wrapper to ensure that all necessary normalization occurs on the
   * message.
   *
   * @param array $msg
   *
   * @return array
   * @throws \Civi\WMFException\WMFException
   */
  protected function normalizeMessage($msg) {
    $skipContributionTracking = (isset($msg['gateway']) && $msg['gateway'] === 'amazon')
      || (isset($msg['is_successful_autorescue']) && $msg['is_successful_autorescue']);

    if (!$skipContributionTracking && !isset($msg['contribution_tracking_id'])) {
      $msg['contribution_tracking_id'] = recurring_get_contribution_tracking_id($msg);
    }

    if (empty($msg['contribution_recur_id']) && !empty($msg['subscr_id'])) {
      $recurRecord = RecurHelper::getByGatewaySubscriptionId($msg['gateway'], $msg['subscr_id']);
      if ($recurRecord) {
        $msg['contribution_recur_id'] = $recurRecord['id'];
      }
    }

    if (isset($msg['frequency_unit'])) {
      if (!in_array($msg['frequency_unit'], ['day', 'week', 'month', 'year'])) {
        throw new WMFException(WMFException::INVALID_RECURRING, "Bad frequency unit: {$msg['frequency_unit']}");
      }
    }

    //Seeing as we're in the recurring module...
    $msg['recurring'] = TRUE;
    $message = new RecurDonationMessage($msg);
    $msg = $message->normalize();
    return $msg;
  }

  /**
   * Import a recurring payment
   *
   * @param array $msg
   *
   * @throws \Civi\WMFException\WMFException
   */
  protected function importSubscriptionPayment($msg) {
    /**
     * if the subscr_id is not set, we can't process it due to an error in the message.
     *
     * otherwise, check for the parent record in civicrm_contribution_recur.
     * if one does not exist, the message is not ready for reprocessing, so requeue it.
     *
     * otherwise, process the payment.
     */
    if (!isset($msg['subscr_id'])) {
      throw new WMFException(WMFException::INVALID_RECURRING, 'Msg missing the subscr_id; cannot process.');
    }
    // check for parent record in civicrm_contribution_recur and fetch its id
    $recur_record = wmf_civicrm_get_gateway_subscription($msg['gateway'], $msg['subscr_id']);

    if ($recur_record && RecurHelper::gatewayManagesOwnRecurringSchedule($msg['gateway'])) {
      // If parent record is mistakenly marked as Completed, Cancelled, or Failed, reactivate it
      RecurHelper::reactivateIfInactive($recur_record);
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
      strpos($msg['gateway'], 'paypal') === 0 &&
      strpos($msg['subscr_id'], 'I-') === 0
    ) {
      $recur_record = wmf_civicrm_get_legacy_paypal_subscription($msg);
      if ($recur_record) {
        Civi::log('wmf')->info('Updating legacy paypal contribution recur row');
        // We found an existing legacy PayPal recurring record for the email.
        // Update it to make sure it's not mistakenly canceled, and while we're
        // at it, stash the new subscr_id in unused field processor_id, in case
        // we need it later.
        wmf_civicrm_update_legacy_paypal_subscription($recur_record, $msg);
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
          'subscr_id' => $msg['subscr_id'],
          'contribution_tracking_id' => $msg['contribution_tracking_id'],
          'email' => $msg['email'],
          'first_name' => $msg['first_name'],
          'middle_name' => $msg['middle_name'],
          'last_name' => $msg['last_name'],
          'street_address' => $msg['street_address'],
          'city' => $msg['city'],
          'state_province' => $msg['state_province'],
          'country' => $msg['country'],
          'postal_code' => $msg['postal_code'],
          // Assuming monthly donation
          'frequency_interval' => '1',
          'frequency_unit' => 'month',
          'installments' => 0,
          'original_gross' => $msg['original_gross'] ?? NULL,
          'original_currency' => $msg['original_currency'] ?? NULL,
          'gross' => $msg['gross'],
          'currency' => $msg['currency'],
          'create_date' => $msg['date'],
          'start_date' => $msg['date'],
          'date' => $msg['date'],
          'gateway' => $msg['gateway'],
          'recurring' => TRUE,
        ];
        $startMessage = $this->normalizeMessage($startMessage);
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

    if (!RecurHelper::isFirst($recur_record->id)) {
      $msg['financial_type_id'] = RecurHelper::getFinancialTypeForSubsequentContributions();
    }
    //insert the contribution
    $contribution = wmf_civicrm_contribution_message_import($msg);

    /**
     *  Insert the contribution record.
     *
     *  PayPal only sends us full address information for the user in payment messages,
     *  but we only want to insert this data once unless we're modifying the record.
     *  We know that this should be the first time we're processing a contribution
     *  for this given user if we are also updating the contribution_tracking table
     *  for this contribution.
     */
    $ctRecord = wmf_civicrm_get_contribution_tracking($msg);
    if (empty($ctRecord['contribution_id'])) {
      // TODO: this scenario should be handled by the wmf_civicrm_contribution_message_import function.

      // Map the tracking record to the CiviCRM contribution
      wmf_civicrm_message_update_contribution_tracking($msg, $contribution);

      // update the contact
      wmf_civicrm_message_contact_update($msg, $recur_record->contact_id);
    }

    // update subscription record with next payment date
    if (isset($msg['date'])) {
      $date = $msg['date'];
    }
    else {
      // TODO: Remove this when audit and IPN are sending normalized messages
      $date = $msg['payment_date'];
    }

    $update_params = [
      'id' => $recur_record->id,
    ];
    $scheduleCalculationParams = [
      'cycle_day' => $recur_record->cycle_day,
      'frequency_interval' => $recur_record->frequency_interval,
    ];
    // Old PayPal donations didn't record the cycle_day, so use the donation's day and update the
    // old record. Should be able to remove this code in March or April 2024 once all old records
    // are updated.
    if (
      strpos($msg['gateway'], 'paypal') === 0 &&
      date('j', strtotime(wmf_common_date_unix_to_civicrm($date))) !== $recur_record->cycle_day
    ) {
      $update_params['cycle_day'] = date('j', strtotime(wmf_common_date_unix_to_civicrm($date)));
      $scheduleCalculationParams['cycle_day'] = $update_params['cycle_day'];
    }
    $update_params['next_sched_contribution_date'] = CRM_Core_Payment_Scheduler::getNextDateForMonth(
      $scheduleCalculationParams
    );

    if (!empty($msg['is_auto_rescue_retry'])) {
      $update_params['contribution_status_id:name'] = 'In Progress';
    }
    $this->updateContributionRecurWithErrorHandling($update_params);

    // construct an array of useful info to invocations of queue2civicrm_import
    $contribution_info = [
      'contribution_id' => $contribution['id'],
      'contact_id' => $recur_record->contact_id,
      'msg' => $msg,
    ];

    // Send thank you email, other post-import things
    module_invoke_all('queue2civicrm_import', $contribution_info);
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
  protected function importSubscriptionAccount($msg) {
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
   * @param $msg
   *
   * @return void
   * @throws \CRM_Core_Exception
   * @throws \Civi\API\Exception\UnauthorizedException
   * @throws \Civi\WMFException\WMFException
   */
  protected function subscrModify($msg) {
    if (!isset($msg['contribution_recur_id'])) {
      throw new WMFException(WMFException::INVALID_RECURRING, 'Invalid message type');
    }
    switch ($msg['txn_type']) {
      case "recurring_upgrade_decline":
        if (!isset($msg['contact_id'])) {
          throw new WMFException(WMFException::INVALID_RECURRING, 'Invalid contact_id');
        }
        $this->upgradeRecurDecline($msg);
        break;
      case "recurring_upgrade":
        if (!isset($msg['amount'])) {
          throw new WMFException(WMFException::INVALID_RECURRING, 'Trying to upgrade recurring subscription but amount is not set');
        }
        $this->upgradeRecurAmount($msg);
        break;
      case "recurring_downgrade":
        if (!isset($msg['amount'])) {
          throw new WMFException(WMFException::INVALID_RECURRING, 'Trying to downgrade recurring subscription but amount is not set');
        }
        $this->downgradeRecurAmount($msg);
        break;
      default:
        throw new WMFException(WMFException::INVALID_RECURRING, 'Unknown transaction type');
    }
  }

  /**
   * Decline upgrade recurring
   *
   * Completes the process of upgrading the contribution recur
   * if the donor decline
   *
   * @param array $msg
   *
   * @throws \Civi\WMFException\WMFException
   */
  protected function upgradeRecurDecline($msg) {
    Activity::create(FALSE)
      ->addValue('activity_type_id', self::RECURRING_UPGRADE_DECLINE_ACTIVITY_TYPE_ID)
      ->addValue('source_record_id', $msg['contribution_recur_id'])
      ->addValue('status_id:name', 'Completed')
      ->addValue('subject', "Decline recurring update")
      ->addValue('details', "Decline recurring update")
      ->addValue('source_contact_id', $msg['contact_id'])
      ->execute();
  }

  protected function getSubscrModificationParameters($msg, $recur_record): array {
    $amountDetails = [
      "native_currency" => $msg['currency'],
      "native_original_amount" => $recur_record['amount'],
      "usd_original_amount" => round(exchange_rate_convert($msg['currency'], $recur_record['amount']), 2),
    ];
    $activityParams = [
      'amount' => $msg['amount'],
      'contact_id' => $recur_record['contact_id'],
      'contribution_recur_id' => $recur_record['id'],
    ];
    return [$amountDetails, $activityParams];
  }

  /**
   * Upgrade Contribution Recur Amount
   *
   * Completes the process of upgrading the contribution recur amount
   * if the donor agrees
   *
   * @param array $msg
   *
   * @throws \Civi\WMFException\WMFException
   */
  protected function upgradeRecurAmount($msg) {
    $recur_record = ContributionRecur::get(FALSE)
      ->addWhere('id', '=', $msg['contribution_recur_id'])
      ->execute()
      ->first();

    if ($msg['amount'] < $recur_record['amount']) {
      throw new WMFException(WMFException::INVALID_RECURRING, 'upgradeRecurAmount: New recurring amount is less than the original amount.');
    }
    [$amountDetails, $activityParams] = $this->getSubscrModificationParameters($msg, $recur_record);
    $amountAdded = $msg['amount'] - $recur_record['amount'];
    $amountDetails['native_amount_added'] = $amountAdded;
    $amountDetails['usd_amount_added'] = round(exchange_rate_convert($msg['currency'], $amountAdded), 2);

    $activityParams['subject'] = "Added " . $amountAdded . " " . $msg['currency'];
    $activityParams['activity_type_id'] = self::RECURRING_UPGRADE_ACCEPT_ACTIVITY_TYPE_ID;
    $this->updateContributionRecurAndRecurringActivity($amountDetails, $activityParams);
  }

  /**
   * Downgrade Contribution Recur Amount
   *
   * Completes the process of downgrading the contribution recur amount
   *
   * @param array $msg
   *
   * @throws \Civi\WMFException\WMFException
   */
  protected function downgradeRecurAmount($msg) {
    $recur_record = ContributionRecur::get(FALSE)
      ->addWhere('id', '=', $msg['contribution_recur_id'])
      ->execute()
      ->first();
    if ($msg['amount'] > $recur_record['amount']) {
      throw new WMFException(WMFException::INVALID_RECURRING, 'downgradeRecurAmount: New recurring amount is greater than the original amount.');
    }
    [$amountDetails, $activityParams] = $this->getSubscrModificationParameters($msg, $recur_record);
    $amountRemoved = $recur_record['amount'] - $msg['amount'];
    $amountDetails['native_amount_removed'] = $amountRemoved;
    $amountDetails['usd_amount_removed'] = round(exchange_rate_convert($msg['currency'], $amountRemoved), 2);

    $activityParams['subject'] = "Recurring amount reduced by " . $amountRemoved . " " . $msg['currency'];
    $activityParams['activity_type_id'] = self::RECURRING_DOWNGRADE_ACTIVITY_TYPE_ID;
    $this->updateContributionRecurAndRecurringActivity($amountDetails, $activityParams);
  }

  /**
   * Import a subscription signup message
   *
   * @param array $msg
   *
   * @throws \Civi\WMFException\WMFException
   */
  protected function importSubscriptionSignup($msg) {
    $contact = NULL;
    // ensure there is not already a record of this account - if so, mark the message as succesfuly processed
    if (!empty($msg['contribution_recur_id'])) {
      throw new WMFException(WMFException::DUPLICATE_CONTRIBUTION, 'Subscription account already exists');
    }
    $ctRecord = wmf_civicrm_get_contribution_tracking($msg);
    if (empty($ctRecord['contribution_id'])) {
      // create contact record
      $contact = wmf_civicrm_message_contact_insert($msg);

      $contactId = $contact['id'];
    }
    else {
      $contactId = civicrm_api3('Contribution', 'getvalue', [
        'id' => $ctRecord['contribution_id'],
        'return' => 'contact_id',
      ]);
    }

    try {
      $params = [
        'contact_id' => $contactId,
        'currency' => $msg['original_currency'],
        'amount' => $msg['original_gross'],
        'frequency_unit' => $msg['frequency_unit'],
        'frequency_interval' => $msg['frequency_interval'],
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
        $params['trxn_id'] = \WmfTransaction::from_message($msg)
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

      if (isset($msg['fiscal_number'])) {
        // TODO handle this in the create contact block above rather than creating and then updating
        $save = new Save('WMFContact', 'save');
        $save->handleUpdate([
          'contact_id' => $contactId,
          'fiscal_number' => $msg['fiscal_number'],
        ]);
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
    catch (\CRM_Core_Exception $e) {
      if (in_array($e->getErrorCode(), ['constraint violation', 'deadlock', 'database lock timeout'], TRUE)) {
        throw new WMFException(WMFException::DATABASE_CONTENTION, 'Contribution not saved due to database load', $e->getErrorData());
      }
    }
  }

  protected function updateContributionRecurWithErrorHandling($params) {
    try {
      return $this->updateContributionRecur($params);
    }
    catch (\CRM_Core_Exception $e) {
      if (in_array($e->getErrorCode(), ['constraint violation', 'deadlock', 'database lock timeout'], TRUE)) {
        throw new WMFException(WMFException::DATABASE_CONTENTION, 'Contribution not saved due to database load', $e->getErrorData());
      }
    }
  }

  protected function createContributionRecur($params) {
    return ContributionRecur::create(FALSE)
      ->setValues($params)
      ->execute()
      ->first();
  }

  protected function updateContributionRecur($params) {
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
      $params = [
        'id' => $msg['contribution_recur_id'],
        'failure_count' => $msg['failure_count'],
        'failure_retry_date' => wmf_common_date_unix_to_civicrm($msg['failure_retry_date']),
      ];
      $this->createContributionRecurWithErrorHandling($params);
    }
    catch (\CRM_Core_Exception $e) {
      throw new WMFException(WMFException::INVALID_RECURRING, 'There was a problem updating the subscription for failed payment for subscriber id: ' . print_r($msg['subscr_id'], TRUE) . ": " . $e->getMessage());
    }
    Civi::log('wmf')->notice('recurring: Successfully recorded failed payment for subscriber id: {subscriber_id} ', ['subscriber_id' => print_r($msg['subscr_id'], TRUE)]);
  }

  /**
   * @param array $amountDetails
   * @param array $activityParams array containing activity and contribution recur data
   * - contact_id (number): required
   * - contribution_recur_id (number): required
   * - activity_type_id (string): required
   * - amount (string): required
   * - subject (string): required
   *
   * @throws \CRM_Core_Exception
   * @throws \Civi\API\Exception\UnauthorizedException
   */
  protected function updateContributionRecurAndRecurringActivity($amountDetails = [], array $activityParams): void {
    $additionalData = json_encode($amountDetails);

    ContributionRecur::update(FALSE)->addValue('amount', $activityParams['amount'])->addWhere(
      'id',
      '=',
      $activityParams['contribution_recur_id']
    )->execute();

    Activity::create(FALSE)
      ->addValue('activity_type_id', $activityParams['activity_type_id'])
      ->addValue(
        'source_record_id',
        $activityParams['contribution_recur_id']
      )
      ->addValue('status_id:name', 'Completed')
      ->addValue('subject', $activityParams['subject'])
      ->addValue('details', $additionalData)
      ->addValue('source_contact_id', $activityParams['contact_id'])
      ->execute();
  }

}

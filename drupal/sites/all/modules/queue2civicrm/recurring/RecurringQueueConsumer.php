<?php namespace queue2civicrm\recurring;

use Civi\Api4\ContributionRecur;
use Civi\WMFException\WMFException;
use Civi\WMFThankYou\From;
use wmf_common\TransactionalWmfQueueConsumer;


class RecurringQueueConsumer extends TransactionalWmfQueueConsumer {

  /**
   * Import messages about recurring payments
   *
   * @param array $message
   *
   * @throws \Civi\WMFException\WMFException
   */
  public function processMessage($message) {
    // store the original message for logging later
    $msg_orig = $message;

    $message = $this->normalizeMessage($message);

    /**
     * prepare data for logging
     *
     * if we don't have a gateway_txn_id, we'll store the transaction type + the subscriber id instead -
     * this should happen for all non-payment transactions.
     */
    $log = [
      'gateway' => 'recurring_' . $message['gateway'],
      'gateway_txn_id' => (!empty($message['gateway_txn_id_orig']) ? $message['gateway_txn_id_orig'] : $message['txn_type'] . ":" . $message['subscr_id']),
      'data' => json_encode($msg_orig),
      'timestamp' => time(),
      'verified' => 0,
    ];
    $cid = _queue2civicrm_log($log);

    // define the subscription txn type for an actual 'payment'
    $txn_subscr_payment = ['subscr_payment'];

    // define the subscription txn types that affect the subscription account
    $txn_subscr_acct = [
      'subscr_cancel', // subscription canceled by user at the gateway.
      'subscr_eot', // subscription expired
      'subscr_failed', // failed signup
      //'subscr_modify', // subscription modification
      'subscr_signup', // subscription account creation
    ];

    // route the message to the appropriate handler depending on transaction type
    if (isset($message['txn_type']) && in_array($message['txn_type'], $txn_subscr_payment)) {
      if (wmf_civicrm_get_contributions_from_gateway_id($message['gateway'], $message['gateway_txn_id'])) {
        watchdog('recurring', "Duplicate contribution: {$message['gateway']}-{$message['gateway_txn_id']}.");
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

    // update the log
    if ($cid) {
      $log['cid'] = $cid;
      $log['timestamp'] = time();
      $log['verified'] = 1;
      _queue2civicrm_log($log);
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

    if (isset($msg['gateway']) && $msg['gateway'] === 'amazon') {
      // should not require special normalization
    }
    else {
      if (!isset($msg['contribution_tracking_id'])) {
        $msg_normalized['contribution_tracking_id'] = recurring_get_contribution_tracking_id($msg);
      }
    }

    if (isset($msg['frequency_unit'])) {
      if (!in_array($msg['frequency_unit'], ['day', 'week', 'month', 'year'])) {
        throw new WMFException(WMFException::INVALID_RECURRING, "Bad frequency unit: {$msg['frequency_unit']}");
      }
    }

    //Seeing as we're in the recurring module...
    $msg['recurring'] = TRUE;

    $msg = wmf_civicrm_normalize_msg($msg);
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
    $recur_record = wmf_civicrm_get_recur_record($msg['subscr_id']);

    if ($recur_record) {
      // If parent record is mistakenly marked as Completed, Cancelled, or Failed, reactivate it
      \Civi\WMFHelpers\ContributionRecur::reactivateIfInactive( $recur_record );
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
        $recur_record = wmf_civicrm_get_recur_record($msg['subscr_id']);
        if (!$recur_record) {
          watchdog('recurring', 'Fallback contribution_recur record creation failed.');
          throw new WMFException(
            WMFException::IMPORT_SUBSCRIPTION,
            "Could not create the initial recurring record for subscr_id {$msg['subscr_id']}"
          );
        }
      }
    }
    if (!$recur_record) {
      watchdog('recurring', 'Msg does not have a matching recurring record in civicrm_contribution_recur; requeueing for future processing.');
      throw new WMFException(WMFException::MISSING_PREDECESSOR, "Missing the initial recurring record for subscr_id {$msg['subscr_id']}");
    }

    $msg['contact_id'] = $recur_record->contact_id;
    $msg['contribution_recur_id'] = $recur_record->id;

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
      'next_sched_contribution_date' => wmf_common_date_unix_to_civicrm(strtotime("+" . $recur_record->frequency_interval . " " . $recur_record->frequency_unit, $date)),
      'id' => $recur_record->id,
    ];
    civicrm_api3('ContributionRecur', 'Create', $update_params);

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
        $this->importSubscriptionModification($msg);
        break;

      case 'subscr_failed':
        $this->importSubscriptionPaymentFailed($msg);
        break;

      default:
        throw new WMFException(WMFException::INVALID_RECURRING, 'Invalid subscription message type');
    }
  }

  /**
   * Import a subscription signup message
   *
   * @param array $msg
   *
   * @throws \Civi\WMFException\WMFException
   */
  protected function importSubscriptionSignup($msg) {
    // ensure there is not already a record of this account - if so, mark the message as succesfuly processed
    if ($recur_record = wmf_civicrm_get_gateway_subscription($msg['gateway'], $msg['subscr_id'])) {
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
      ];
      if ($this->getPaymentProcessorID($msg['gateway'])) {
        // We could pass the gateway name to the api for resolution but it would reject
        // any gateway values with no valid processor mapping so we do this ourselves.
        $params['payment_processor_id'] = $this->getPaymentProcessorID($msg['gateway']);
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
        $params['next_sched_contribution_date'] = wmf_common_date_unix_to_civicrm($msg['start_date']);
        $params['cycle_day'] = date('j', strtotime($params['start_date']));
      }

      $newContributionRecur = civicrm_api3('ContributionRecur', 'create', $params);

      // Send an email that the recurring donation has been created
      if (isset($msg['recurring_payment_token']) && isset($newContributionRecur['id'])) {
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
          watchdog('monthly_convert', "Donor language unknown.  Defaulting to English...", NULL, WATCHDOG_INFO);
          $locale = 'en';
        }
        $locale = wmf_common_locale_civi_to_mediawiki($locale);

        // Using the same params sent through in thank_you.module thank_you_for_contribution
        $template = 'monthly_convert';
        $start_date = $newContributionRecur['values'][$newContributionRecur['id']]['start_date'];

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
          // used for the bounce header
          'unsubscribe_link' => build_unsub_link($ctRecord['contribution_id'], $contact['email'], $locale),
          'contribution_tags' => '',
        ];

        $success = thank_you_send_mail($params);
        if ($success) {
          watchdog('monthly_convert', "Monthly convert sent successfully for recurring contribution id: " . $newContributionRecur['id'] . " to " . $params['recipient_address'], [], WATCHDOG_INFO);
        }
        else {
          watchdog('monthly_convert', "Monthly convert mail failed for recurring contribution id: " . $newContributionRecur['id'] . " to " . $params['recipient_address'], [], WATCHDOG_ERROR);
        }
      }
    } catch (\CiviCRM_API3_Exception $e) {
      throw new WMFException(WMFException::IMPORT_CONTRIB, 'Failed inserting subscriber signup for subscriber id: ' . print_r($msg['subscr_id'], TRUE) . ': ' . $e->getMessage());
    }
    watchdog('recurring', 'Succesfully inserted subscription signup for subscriber id: %subscr_id ', ['%subscr_id' => print_r($msg['subscr_id'], TRUE)], WATCHDOG_NOTICE);
  }

  /**
   * Process a subscriber cancellation
   *
   * @param array $msg
   *
   * @throws \Civi\WMFException\WMFException
   */
  protected function importSubscriptionCancel($msg) {
    // ensure we have a record of the subscription
    if (!$recur_record = wmf_civicrm_get_recur_record($msg['subscr_id'])) {
      // PayPal has recently been sending lots of invalid cancel and fail notifications
      // Revert this patch when that's resolved
      return;
      // throw new WMFException(WMFException::INVALID_RECURRING, 'Subscription account does not exist');
    }

    $cancelStatus = civicrm_api3('ContributionRecur', 'cancel', [
      'id' => $recur_record->id,
      // This line of code is only reachable if the txn type is 'subscr_cancel'
      // Which I believe always means the user has initiated the cancellation outside our process.
      'cancel_reason' => '(auto) User Cancelled via Gateway',
    ]);
    if ($cancelStatus['is_error']) {
      throw new WMFException(WMFException::INVALID_RECURRING, 'There was a problem cancelling the subscription for subscriber id: ' . print_r($msg['subscr_id'], TRUE));
    }

    if ($msg['cancel_date']) {
      // Set cancel and end dates to match those from message.
      $api = civicrm_api_classapi();
      $update_params = [
        'id' => $recur_record->id,
        'cancel_date' => wmf_common_date_unix_to_civicrm($msg['cancel_date']),
        'end_date' => wmf_common_date_unix_to_civicrm($msg['cancel_date']),
        'version' => 3,
      ];
      if (!$api->ContributionRecur->Create($update_params)) {
        throw new WMFException(WMFException::INVALID_RECURRING, 'There was a problem updating the subscription for cancelation for subscriber id: ' . print_r($msg['subscr_id'], TRUE) . ": " . $api->errorMsg());
      }
    }
    watchdog('recurring', 'Succesfuly cancelled subscription for subscriber id %subscr_id', ['%subscr_id' => print_r($msg['subscr_id'], TRUE)], WATCHDOG_NOTICE);
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
    if (!$recur_record = wmf_civicrm_get_recur_record($msg['subscr_id'])) {
      // PayPal has recently been sending lots of invalid cancel and fail notifications
      // Revert this patch when that's resolved
      return;
      // throw new WMFException(WMFException::INVALID_RECURRING, 'Subscription account does not exist');
    }

    try {
      // See function comment block for discussion.
      \civicrm_api3('ContributionRecur', 'create', [
        'id' => $recur_record->id,
        'end_date' => 'now',
        'contribution_status_id' => 'Completed',
        'cancel_reason' => '(auto) Expiration notification',
        'next_sched_contribution_date' => 'null',
        'failure_retry_date' => 'null',
      ]);
    } catch (\CiviCRM_API3_Exception $e) {
      throw new WMFException(WMFException::INVALID_RECURRING, 'There was a problem updating the subscription for EOT for subscription id: %subscr_id' . print_r($msg['subscr_id'], TRUE) . ": " . $e->getMessage());
    }
    watchdog('recurring', 'Succesfuly ended subscription for subscriber id: %subscr_id ', ['%subscr_id' => print_r($msg['subscr_id'], TRUE)], WATCHDOG_NOTICE);
  }

  /**
   * Process a subscription modification
   *
   * NOTE: at the moment, we are not accepting modification messages, so this
   * is currently unused.
   *
   * @param array $msg
   *
   * @throws \Civi\WMFException\WMFException
   */
  protected function importSubscriptionModification($msg) {
    // ensure we have a record of the subscription
    if (!$recur_record = wmf_civicrm_get_recur_record($msg['subscr_id'])) {
      throw new WMFException(WMFException::INVALID_RECURRING, 'Subscription account does not exist for subscription id: ' . print_r($msg['subscr_id'], TRUE));
    }

    $api = civicrm_api_classapi();
    $update_params = [
      'id' => $recur_record->id,

      'amount' => $msg['original_gross'],
      'frequency_unit' => $msg['frequency_unit'],
      'frequency_interval' => $msg['frequency_interval'],
      'modified_date' => wmf_common_date_unix_to_civicrm($msg['modified_date']),
      //FIXME: looks wrong to base off of start_date
      'next_sched_contribution_date' => wmf_common_date_unix_to_civicrm(strtotime("+" . $recur_record->frequency_interval . " " . $recur_record->frequency_unit, $msg['start_date'])),

      'version' => 3,
    ];
    if (!$api->ContributionRecur->Create($update_params)) {
      throw new WMFException(WMFException::INVALID_RECURRING, 'There was a problem updating the subscription record for subscription id ' . print_r($msg['subscr_id'], TRUE) . ": " . $api->errorMsg());
    }

    // update the contact
    wmf_civicrm_message_contact_update($msg, $recur_record->contact_id);

    watchdog('recurring', 'Subscription succesfully modified for subscription id: %subscr_id', ['%subscr_id' => print_r($msg['subscr_id'], TRUE)], WATCHDOG_NOTICE);
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
    if (!$recur_record = wmf_civicrm_get_recur_record($msg['subscr_id'])) {
      // PayPal has recently been sending lots of invalid cancel and fail notifications
      // Revert this patch when that's resolved
      return;
      // throw new WMFException(WMFException::INVALID_RECURRING, 'Subscription account does not exist for subscription id: ' . print_r($msg['subscr_id'], TRUE));
    }

    $api = civicrm_api_classapi();
    $update_params = [
      'id' => $recur_record->id,
      'failure_count' => $msg['failure_count'],
      'failure_retry_date' => wmf_common_date_unix_to_civicrm($msg['failure_retry_date']),

      'version' => 3,
    ];
    if (!$api->ContributionRecur->Create($update_params)) {
      throw new WMFException(WMFException::INVALID_RECURRING, 'There was a problem updating the subscription for failed payment for subscriber id: ' . print_r($msg['subscr_id'], TRUE) . ": " . $api->errorMsg());
    }
    else {
      watchdog('recurring', 'Successfully canceled subscription for failed payment for subscriber id: %subscr_id ', ['%subscr_id' => print_r($msg['subscr_id'], TRUE)], WATCHDOG_NOTICE);
    }
  }

  /**
   * Get the available payment processors.
   *
   * @return int[]
   *   e.g ['adyen' => 1, 'paypal_ec' => 1]
   *
   * @throws \API_Exception
   */
  protected function getPaymentProcessors(): array {
    // Note that the options themselves are cached already in core.
    // This caching doesn't add much more.
    $processors = \Civi::cache('metadata')->get('queue2civicrm_civicrm_payment_processors');
    if (!$processors) {
      $processors = [];
      $options = ContributionRecur::getFields(FALSE)
        ->setLoadOptions(['id', 'name'])
        ->addWhere('name', '=', 'payment_processor_id')
        ->execute()->first()['options'];
      foreach ($options as $option) {
        $processors[$option['name']] = $option['id'];
      }
      \Civi::cache('metadata')->set('queue2civicrm_civicrm_payment_processors', $processors);
    }
    return $processors;
  }

  /**
   * Get the payment processor id for our gateway.
   *
   * @param string $gateway
   *
   * @return int[]|false
   *
   * @throws \API_Exception
   */
  protected function getPaymentProcessorID(string $gateway) {
    $processors = $this->getPaymentProcessors();
    return $processors[$gateway] ?? FALSE;
  }

}

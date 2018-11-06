<?php namespace queue2civicrm\recurring;

use CRM_Contribute_BAO_ContributionRecur;
use CRM_Core_DAO;
use wmf_common\TransactionalWmfQueueConsumer;
use WmfException;

class RecurringQueueConsumer extends TransactionalWmfQueueConsumer {

  /**
   * Import messages about recurring payments
   *
   * @param array $message
   *
   * @throws WmfException
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
      'subscr_cancel', // subscription canceled
      'subscr_eot', // subscription expired
      'subscr_failed', // failed signup
      //'subscr_modify', // subscription modification
      'subscr_signup', // subscription account creation
    ];

    // route the message to the appropriate handler depending on transaction type
    if (isset($message['txn_type']) && in_array($message['txn_type'], $txn_subscr_payment)) {
      if (wmf_civicrm_get_contributions_from_gateway_id($message['gateway'], $message['gateway_txn_id'])) {
        watchdog('recurring', "Duplicate contribution: {$message['gateway']}-{$message['gateway_txn_id']}.");
        throw new WmfException(WmfException::DUPLICATE_CONTRIBUTION, "Contribution already exists. Ignoring message.");
      }
      $this->importSubscriptionPayment($message);
    }
    elseif (isset($message['txn_type']) && in_array($message['txn_type'], $txn_subscr_acct)) {
      $this->importSubscriptionAccount($message);
    }
    else {
      throw new WmfException(WmfException::INVALID_RECURRING, 'Msg not recognized as a recurring payment related message.');
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
   * @throws WmfException
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
        throw new WmfException(WmfException::INVALID_RECURRING, "Bad frequency unit: {$msg['frequency_unit']}");
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
   * @throws WmfException
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
      throw new WmfException(WmfException::INVALID_RECURRING, 'Msg missing the subscr_id; cannot process.');
    }
    // check for parent record in civicrm_contribution_recur and fetch its id
    $recur_record = wmf_civicrm_get_recur_record($msg['subscr_id']);
    // Fall back to searching by email in case the processor is pulling some
    // horrible subscription ID swap shenanigans
    if (!$recur_record && !empty($msg['email'])) {
      $recur_record = wmf_civicrm_get_subscription_by_email($msg['email']);
    }
    if (!$recur_record) {
      watchdog('recurring', 'Msg does not have a matching recurring record in civicrm_contribution_recur; requeueing for future processing.');
      throw new WmfException(WmfException::MISSING_PREDECESSOR, "Missing the initial recurring record for subscr_id {$msg['subscr_id']}");
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
      $contact = wmf_civicrm_message_contact_update($msg, $recur_record->contact_id);

      // Insert the location record
      // This will be duplicated in some cases in the main message_import, but should
      // not have a negative impact. Longer term it should be removed from here in favour of there.
      wmf_civicrm_message_location_update($msg, $contact);
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
   * @throws WmfException
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
        throw new WmfException(WmfException::INVALID_RECURRING, 'Invalid subscription message type');
    }
  }

  /**
   * Import a subscription signup message
   *
   * @param array $msg
   *
   * @throws WmfException
   */
  protected function importSubscriptionSignup($msg) {
    // ensure there is not already a record of this account - if so, mark the message as succesfuly processed
    if ($recur_record = wmf_civicrm_get_recur_record($msg['subscr_id'])) {
      throw new WmfException(WmfException::DUPLICATE_CONTRIBUTION, 'Subscription account already exists');
    }

    // create contact record
    $contact = wmf_civicrm_message_contact_insert($msg);

    // Insert the location record
    wmf_civicrm_message_location_insert($msg, $contact);

    try {
      $result = civicrm_api3('ContributionRecur', 'create', [
        'contact_id' => $contact['id'],
        'currency' => $msg['original_currency'],
        'amount' => $msg['original_gross'],
        'frequency_unit' => $msg['frequency_unit'],
        'frequency_interval' => $msg['frequency_interval'],
        'installments' => $msg['installments'],
        'start_date' => wmf_common_date_unix_to_civicrm($msg['start_date']),
        'create_date' => wmf_common_date_unix_to_civicrm($msg['create_date']),
        'trxn_id' => $msg['subscr_id'],
      ]);
    } catch (\CiviCRM_API3_Exception $e) {
      throw new WmfException(WmfException::IMPORT_CONTRIB, 'Failed inserting subscriber signup for subscriber id: ' . print_r($msg['subscr_id'], TRUE) . ': ' . $e->getMessage());
    }
    watchdog('recurring', 'Succesfully inserted subscription signup for subscriber id: %subscr_id ', ['%subscr_id' => print_r($msg['subscr_id'], TRUE)], WATCHDOG_NOTICE);
  }

  /**
   * Process a subscriber cancellation
   *
   * @param array $msg
   *
   * @throws WmfException
   */
  protected function importSubscriptionCancel($msg) {
    // ensure we have a record of the subscription
    if (!$recur_record = wmf_civicrm_get_recur_record($msg['subscr_id'])) {
      // PayPal has recently been sending lots of invalid cancel and fail notifications
      // Revert this patch when that's resolved
      return;
      // throw new WmfException(WmfException::INVALID_RECURRING, 'Subscription account does not exist');
    }
    $activityParams = [
      'subject' => ts('Recurring contribution cancelled'),
    ];
    $cancelStatus = CRM_Contribute_BAO_ContributionRecur::cancelRecurContribution(
      $recur_record->id,
      $activityParams
    );
    if (!$cancelStatus) {
      throw new WmfException(WmfException::INVALID_RECURRING, 'There was a problem cancelling the subscription for subscriber id: ' . print_r($msg['subscr_id'], TRUE));
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
        throw new WmfException(WmfException::INVALID_RECURRING, 'There was a problem updating the subscription for cancelation for subscriber id: ' . print_r($msg['subscr_id'], TRUE) . ": " . $api->errorMsg());
      }
    }
    watchdog('recurring', 'Succesfuly cancelled subscription for subscriber id %subscr_id', ['%subscr_id' => print_r($msg['subscr_id'], TRUE)], WATCHDOG_NOTICE);
  }

  /**
   * Process an expired subscription
   *
   * @param array $msg
   *
   * @throws WmfException
   */
  protected function importSubscriptionExpired($msg) {
    // ensure we have a record of the subscription
    if (!$recur_record = wmf_civicrm_get_recur_record($msg['subscr_id'])) {
      // PayPal has recently been sending lots of invalid cancel and fail notifications
      // Revert this patch when that's resolved
      return;
      // throw new WmfException(WmfException::INVALID_RECURRING, 'Subscription account does not exist');
    }

    $api = civicrm_api_classapi();
    $update_params = [
      'id' => $recur_record->id,
      'end_date' => wmf_common_date_unix_to_civicrm(time()),

      'version' => 3,
    ];
    if (!$api->ContributionRecur->Create($update_params)) {
      throw new WmfException(WmfException::INVALID_RECURRING, 'There was a problem updating the subscription for EOT for subscription id: %subscr_id' . print_r($msg['subscr_id'], TRUE) . ": " . $api->errorMsg());
    }
    else {
      watchdog('recurring', 'Succesfuly ended subscription for subscriber id: %subscr_id ', ['%subscr_id' => print_r($msg['subscr_id'], TRUE)], WATCHDOG_NOTICE);
    }
  }

  /**
   * Process a subscription modification
   *
   * NOTE: at the moment, we are not accepting modification messages, so this
   * is currently unused.
   *
   * @param array $msg
   *
   * @throws WmfException
   */
  protected function importSubscriptionModification($msg) {
    // ensure we have a record of the subscription
    if (!$recur_record = wmf_civicrm_get_recur_record($msg['subscr_id'])) {
      throw new WmfException(WmfException::INVALID_RECURRING, 'Subscription account does not exist for subscription id: ' . print_r($msg['subscr_id'], TRUE));
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
      throw new WmfException(WmfException::INVALID_RECURRING, 'There was a problem updating the subscription record for subscription id ' . print_r($msg['subscr_id'], TRUE) . ": " . $api->errorMsg());
    }

    // update the contact
    $contact = wmf_civicrm_message_contact_update($msg, $recur_record->contact_id);

    // Insert the location record
    wmf_civicrm_message_location_insert($msg, $contact);

    watchdog('recurring', 'Subscription succesfully modified for subscription id: %subscr_id', ['%subscr_id' => print_r($msg['subscr_id'], TRUE)], WATCHDOG_NOTICE);
  }

  /**
   * Process failed subscription payment
   *
   * @param array $msg
   *
   * @throws WmfException
   */
  protected function importSubscriptionPaymentFailed($msg) {
    // ensure we have a record of the subscription
    if (!$recur_record = wmf_civicrm_get_recur_record($msg['subscr_id'])) {
      // PayPal has recently been sending lots of invalid cancel and fail notifications
      // Revert this patch when that's resolved
      return;
      // throw new WmfException(WmfException::INVALID_RECURRING, 'Subscription account does not exist for subscription id: ' . print_r($msg['subscr_id'], TRUE));
    }

    $api = civicrm_api_classapi();
    $update_params = [
      'id' => $recur_record->id,
      'failure_count' => $msg['failure_count'],
      'failure_retry_date' => wmf_common_date_unix_to_civicrm($msg['failure_retry_date']),

      'version' => 3,
    ];
    if (!$api->ContributionRecur->Create($update_params)) {
      throw new WmfException(WmfException::INVALID_RECURRING, 'There was a problem updating the subscription for failed payment for subscriber id: ' . print_r($msg['subscr_id'], TRUE) . ": " . $api->errorMsg());
    }
    else {
      watchdog('recurring', 'Successfully canceled subscription for failed payment for subscriber id: %subscr_id ', ['%subscr_id' => print_r($msg['subscr_id'], TRUE)], WATCHDOG_NOTICE);
    }
  }

}

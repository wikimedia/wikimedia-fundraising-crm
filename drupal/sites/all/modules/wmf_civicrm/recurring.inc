<?php

use Civi\Api4\ContributionRecur;
use Civi\WMFHelper\PaymentProcessor;
use Civi\WMFException\WMFException;
use Civi\WMFTransaction;

/**
 * Insert the recurring contribution record
 *
 * @todo Are the dates being set with the correct value?
 *
 * @param array $msg
 * @param integer $contact_id
 * @param string $subscr_id subscription_id/trnx_id
 * //FIXME: should not pass a contribution in, only subscr_id
 * @param array $contribution The contribution record
 *
 * @return boolean  Returns true on success
 * @throws \Civi\WMFException\WMFException
 * @throws \CRM_Core_Exception
 *
 */
function wmf_civicrm_message_contribution_recur_insert($msg, $contact_id, $subscr_id, $contribution_record = NULL) {
  $msg['frequency_unit'] = isset($msg['frequency_unit']) ? $msg['frequency_unit'] : 'month';
  $msg['frequency_interval'] = isset($msg['frequency_interval']) ? (integer) $msg['frequency_interval'] : 1;
  $msg['installments'] = isset($msg['installments']) ? (integer) $msg['installments'] : 0;
  $msg['cancel'] = isset($msg['cancel']) ? (integer) $msg['cancel'] : 0;

  if (!isset($msg['recurring']) || $msg['recurring'] != 1) {
    $error_message = t(
      'Payment is not marked as recurring, with the contact_id [!contact_id]',
      ["!contact_id" => $contact_id]
    );
    throw new WMFException(WMFException::IMPORT_SUBSCRIPTION, $error_message);
  }

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

  // installments is only allowed to be 0.
  if ($msg['installments'] !== 0) {
    $error_message = t(
      '`installments` must be set to 0, with the contact_id [!contact_id]',
      ["!contact_id" => $contact_id]
    );
    throw new WMFException(WMFException::IMPORT_SUBSCRIPTION, $error_message);
  }

  if (!empty($msg['subscr_id'])) {
    $gateway_subscr_id = $msg['subscr_id'];
  }
  elseif (!empty($msg['gateway_txn_id'])) {
    $gateway_subscr_id = $msg['gateway_txn_id'];
  }
  else {
    // trxn_id must be set.
    // FIXME: this should always be the subscr_id.
    if (empty($contribution_record['trxn_id'])) {
      $error_message = t(
        '`trxn_id` must be set and not empty, with the contact_id [!contact_id]',
        ["!contact_id" => $contact_id]
      );
      throw new WMFException(WMFException::IMPORT_SUBSCRIPTION, $error_message);
    }

    $gateway_subscr_id = $contribution_record['trxn_id'];
  }

  $msg['cycle_day'] = wmf_civicrm_get_cycle_day($msg['date']);

  $next_sched_contribution = CRM_Core_Payment_Scheduler::getNextContributionDate($msg);

  if (!empty($msg['payment_processor_id']) && !empty($msg['payment_token_id'])) {
    // copy existing payment token and processor IDs from message
    $extra_recurring_params = [
      'payment_token_id' => $msg['payment_token_id'],
      'payment_processor_id' => $msg['payment_processor_id'],
      'processor_id' => $gateway_subscr_id,
      'trxn_id' => WMFTransaction::from_message($msg)->get_unique_id(),
    ];
  }
  elseif (!empty($msg['recurring_payment_token']) && $msg['gateway']) {
    // create a recurring payment token record if token is present
    $payment_token_result = wmf_civicrm_recur_payment_token_create(
      $contact_id,
      $msg['gateway'],
      $msg['recurring_payment_token'],
      isset($msg['user_ip']) ? $msg['user_ip'] : NULL
    );
    $extra_recurring_params = [
      'payment_token_id' => $payment_token_result['id'],
      'payment_processor_id' => $payment_token_result['payment_processor_id'],
      'processor_id' => $gateway_subscr_id,
      'trxn_id' => WMFTransaction::from_message($msg)->get_unique_id(),
    ];
  }
  elseif (PaymentProcessor::getPaymentProcessorID($msg['gateway'])) {
    $extra_recurring_params = [
      'payment_processor_id' => PaymentProcessor::getPaymentProcessorID($msg['gateway']),
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

  $insert_params = [
    'payment_instrument_id' => $msg['payment_instrument_id'],
    'contact_id' => $contact_id,
    'amount' => $msg['original_gross'],
    'currency' => $msg['original_currency'],
    'financial_type_id:name' => 'Cash',
    'frequency_unit' => $msg['frequency_unit'],
    'frequency_interval' => $msg['frequency_interval'],
    'installments' => $msg['installments'],
    'start_date' => wmf_common_date_unix_to_civicrm($msg['date']),
    'create_date' => wmf_common_date_unix_to_civicrm($msg['date']),
    'cancel_date' => ($msg['cancel'] ? wmf_common_date_unix_to_civicrm($msg['cancel']) : NULL),
    'cycle_day' => $msg['cycle_day'],
    'next_sched_contribution_date' => $next_sched_contribution,
    'trxn_id' => $gateway_subscr_id,
    'contribution_status_id:name' => 'Pending',
  ] + $extra_recurring_params;

  try {
    $result = ContributionRecur::create(FALSE)
      ->setValues($insert_params)
      ->execute()
      ->first();
  }
  catch (CRM_Core_Exception $e) {
    throw new WMFException(WMFException::IMPORT_SUBSCRIPTION, $e->getMessage());
  }

  $contribution_recur_id = $result['id'];

  if ($contribution_record) {
    // Link the contribution to the recurring payment
    $link_contribution_status = wmf_civicrm_update_contribution_for_recurring($contribution_record['id'], $contribution_recur_id);
    return $link_contribution_status;
  }
  else {
    return TRUE;
  }
}

/**
 * Link a recurring payment to the contribution record
 *
 * @param integer $contribution_id
 * @param integer $contribution_recur_id
 */
function wmf_civicrm_update_contribution_for_recurring($contribution_id, $contribution_recur_id) {
  // contribution_id must be set and not null or zero
  if (empty($contribution_id)) {
    $error_message = t('`contribution_id` must be set and not empty.');
    throw new WMFException(WMFException::IMPORT_SUBSCRIPTION, $error_message);
  }

  // contribution_recur_id must be set and not null or zero
  if (empty($contribution_recur_id)) {
    $error_message = t('`contribution_recur_id` must be set and not empty.');
    throw new WMFException(WMFException::IMPORT_SUBSCRIPTION, $error_message);
  }
  try {
    $result = civicrm_api3('Contribution', 'create', [
      'id' => $contribution_id,
      'contribution_recur_id' => $contribution_recur_id,
    ]);
  }
  catch (CRM_Core_Exception $e) {
    $error_message = t(
      'There was a problem linking the contribution [!contribution_id] to the recurring contribution [!contribution_recur_id]: !msg',
      [
        '!contribution_id' => $contribution_id,
        '!contribution_recur_id' => $contribution_recur_id,
        '!msg' => $e->getMessage(),
      ]
    );
    throw new WMFException(WMFException::IMPORT_SUBSCRIPTION, $error_message);
  }
  \Civi::log('wmf')->notice('wmf_civicrm: Successfully linked the contribution  {contribution_id} to the recurring contribution {contribution_recur_id}', [
    'contribution_id' => $contribution_id,
    'contribution_recur_id' => $contribution_recur_id,
  ]);
  return TRUE;
}

/**
 * @param timestamp $date as unix seconds
 *
 * @return day of the month for this date
 */
function wmf_civicrm_get_cycle_day($date) {
  return intval(gmdate('j', $date));
}

/**
 * Get recurring record in CiviCRM for the given subscriber id
 *
 * There is tension between trxn_id as searchable index and full gateway style
 * at the moment, so we search using either style: either bare gateway
 * subscription ID, or trxn-style full natural key.
 *
 * @param string $subscr_id
 *
 * @return object|false
 * @deprecated use WMFHelper/ContributionRecur::getByGatewaySubscriptionId
 *
 */
function wmf_civicrm_get_gateway_subscription($gateway, $subscr_id) {
  $full_trxn_id = "RECURRING {$gateway} {$subscr_id}";

  $query = "select *
    from civicrm_contribution_recur
    where
        trxn_id = %1
        or trxn_id = %2";
  $dao = CRM_Core_DAO::executeQuery($query, [
    1 => [$subscr_id, 'String'],
    2 => [$full_trxn_id, 'String'],
  ]);

  if (!$dao->fetch()) {
    return FALSE;
  }

  return $dao;
}

/**
 * Get a legacy PayPal subscription associated with the given email address.
 * This and the code that calls it in
 * RecurringQueueConsumer::importSubscriptionPayment should be destroyed when
 * PayPal gives us better guidance on T209008.
 *
 * @param array $msg
 *
 * @return CRM_Core_DAO|false
 */
function wmf_civicrm_get_legacy_paypal_subscription($msg) {
  // This is a janky way to make the test check how many times we do this.
  // However unlike in 2018 we should REALLY be almost ready to remove this code..
  // https://phabricator.wikimedia.org/T355448#9819521
  \Civi::$statics[__FUNCTION__] = isset(\Civi::$statics[__FUNCTION__]) ? \Civi::$statics[__FUNCTION__] + 1 : 1;
  // We include recently-canceled donations because PayPal has apparently
  // not communicated about their ID migration to the team that makes their
  // audit files, leading a ton of subscriptions to be mistakenly canceled
  // starting around October 2018.
  // civicrm_contribution.trxn_id is the individual payment ID prefixed with
  // RECURRING PAYPAL
  // civicrm_contribution_recur.trxn_id is what PayPal sends as the subscr_id
  // field. For legacy subscriptions that was always S-%.
  // In case someone has multiple legacy PayPal subscriptions, prefer the one
  // with the closest amount, then the most recent.
  $query = "SELECT ccr.*
    FROM civicrm_contribution_recur ccr
    INNER JOIN civicrm_email e ON ccr.contact_id = e.contact_id
    INNER JOIN civicrm_contribution c ON c.contribution_recur_id = ccr.id
    WHERE email = %1
    AND c.trxn_id LIKE 'RECURRING PAYPAL %'
    AND ccr.trxn_id LIKE 'S-%'
    AND (ccr.cancel_date IS NULL OR ccr.cancel_date > '2018-09-01')
    AND (ccr.end_date IS NULL OR ccr.end_date > '2018-09-01')
    ORDER BY ABS(ccr.amount - %2) ASC, c.receive_date DESC
    LIMIT 1";
  $dao = CRM_Core_DAO::executeQuery($query, [
    1 => [$msg['email'], 'String'],
    2 => [$msg['gross'], 'Float'],
  ]);

  if (!$dao->fetch()) {
    return FALSE;
  }

  return $dao;
}

/**
 * @param $gateway
 * @param $token
 *
 * @return mixed|null
 * @throws \CRM_Core_Exception
 * @throws \Civi\WMFException\WMFException
 * @deprecated
 *
 * This code has 2 callers - but would ideally they would call a function on the
 * Message class like getPaymentToken() which would do an apiv4 call like
 *
 * PaymentToken::get(FALSE)
 * ->addWhere('payment_processor_id.name', '=', $signupMessage['gateway'])
 * ->addWhere('token', '=', $signupMessage['recurring_payment_token'])
 * ->addOrderBy('created_date', 'DESC')
 * ->execute()->first();
 *
 * The api call is trivial enough that unless the relevant Message classes easily inherit
 * it is likely not worth trying to avoid repeating it.
 */
function wmf_civicrm_get_recurring_payment_token($gateway, $token) {
  $processorId = wmf_civicrm_get_payment_processor_id($gateway);

  $payment_token_result = civicrm_api3('PaymentToken', 'get', [
    'payment_processor_id' => $processorId,
    'token' => $token,
    // Return most recent value, without this the function returns null on multiple rows in values
    'options' => ['sort' => "created_date desc", 'limit' => 1],
  ]);
  if (empty($payment_token_result['values'])) {
    return NULL;
  }

  return $payment_token_result['values'][$payment_token_result['id']];
}

/**
 * Create a recurring payment token to be used alongside a recurring
 * contribution record
 *
 * @param int $contact_id
 * @param string $gateway Short gateway identifier
 * @param string $token Token provided by payment processor
 * @param string $ip Donor's IP address
 *
 * @return array $payment_token_record
 * @throws \Civi\WMFException\WMFException
 */
function wmf_civicrm_recur_payment_token_create($contact_id, $gateway, $token, $ip) {
  $processorId = wmf_civicrm_get_payment_processor_id($gateway);

  try {
    $payment_token_result = civicrm_api3('PaymentToken', 'create', [
      'contact_id' => $contact_id,
      'payment_processor_id' => $processorId,
      'token' => $token,
      'ip_address' => $ip,
    ]);
  } catch (CiviCRM_API3_Exception $e) {
    throw new WMFException(WMFException::IMPORT_SUBSCRIPTION, $e->getMessage());
  }

  //return newly created record
  return $payment_token_result['values'][$payment_token_result['id']];
}

function wmf_civicrm_get_payment_processor_id($processorName) {
  try {
    $payment_processor_result = civicrm_api3('PaymentProcessor', 'getsingle', [
      'name' => $processorName,
      'is_test' => 0,
    ]);
  } catch (CiviCRM_API3_Exception $e) {
    throw new WMFException(
      WMFException::MISSING_PREDECESSOR,
      "No such Payment Processor exists, name='$processorName', " . $e->getMessage()
    );
  }
  return $payment_processor_result['id'];
}

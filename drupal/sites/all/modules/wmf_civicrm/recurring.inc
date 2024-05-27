<?php

use Civi\Api4\ContributionRecur;
use Civi\WMFException\WMFException;

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
 * Get recurring record in CiviCRM for the given subscriber id
 *
 * @todo - this should be handled in Message::normalize().
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
 * @todo - we rolled out a patch in May 2024 that should make this obsolete within a month.
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
 * Get recurring token - to be moved to DonationMessage::normalize().
 *
 * @todo the message object normalize() function should retrieve these values as relevant - like it does with ContributionRecur, ContributionTracking)
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
 * @todo - this should be a method on the QueueConsumer parent class.
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
  }
  catch (CRM_Core_Exception $e) {
    throw new WMFException(WMFException::IMPORT_SUBSCRIPTION, $e->getMessage());
  }

  //return newly created record
  return $payment_token_result['values'][$payment_token_result['id']];
}

/**
 * @todo - this should be handled by Message::normalize().
 * @param $processorName
 *
 * @return mixed
 * @throws \Civi\WMFException\WMFException
 */
function wmf_civicrm_get_payment_processor_id($processorName) {
  try {
    $payment_processor_result = civicrm_api3('PaymentProcessor', 'getsingle', [
      'name' => $processorName,
      'is_test' => 0,
    ]);
  }
  catch (CRM_Core_Exception $e) {
    throw new WMFException(
      WMFException::MISSING_PREDECESSOR,
      "No such Payment Processor exists, name='$processorName', " . $e->getMessage()
    );
  }
  return $payment_processor_result['id'];
}

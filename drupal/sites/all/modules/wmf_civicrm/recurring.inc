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

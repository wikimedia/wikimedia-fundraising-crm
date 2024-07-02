<?php

use Civi\WMFException\WMFException;

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
  try {
    return \Civi\Api4\PaymentToken::create(FALSE)
      ->setValues([
        'contact_id' => $contact_id,
        'payment_processor_id.name' => $gateway,
        'token' => $token,
        'ip_address' => $ip,
      ]
    )->execute()->first();
  }
  catch (CRM_Core_Exception $e) {
    throw new WMFException(WMFException::IMPORT_SUBSCRIPTION, $e->getMessage());
  }
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

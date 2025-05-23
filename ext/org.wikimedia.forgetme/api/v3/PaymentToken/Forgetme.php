<?php
/**
 * PaymentToken.Forgetme API specification (optional)
 * This is used for documentation and validation.
 *
 * @param array $spec description of fields supported by this API call
 *
 * @return void
 * @see http://wiki.civicrm.org/confluence/display/CRMDOC/API+Architecture+Standards
 */
function _civicrm_api3_payment_token_forget_spec(&$spec) {
  $spec['contact_id']['api.required'] = 1;
  $spec['contact_id']['type'] = CRM_Utils_Type::T_INT;
}


/**
 * PaymentToken.Forgetme API
 *
 * @param array $params
 *
 * @return array API result descriptor
 * @see civicrm_api3_create_success
 * @throws CRM_Core_Exception
 */
function civicrm_api3_payment_token_forgetme($params) {
  if (is_numeric($params['contact_id'])) {
    $params['contact_id'] = ['IN' => [$params['contact_id']]];
  }
  //check exists
  $paymentTokenRecords = civicrm_api3('PaymentToken', 'get', $params)['values'];
  if (empty($paymentTokenRecords)) {
    return civicrm_api3_create_success([], $params);
  }

  //clear PII fields
  foreach ($paymentTokenRecords as $paymentToken) {
    $result = civicrm_api3('PaymentToken', 'create', [
      'id' => $paymentToken['id'],
      'contact_id' => $params['contact_id'],
      'payment_processor_id' => $paymentToken['payment_processor_id'],
      'email' => '',
      'masked_account_number' => '',
      'ip_address' => '',
    ]);
  }

  return civicrm_api3_create_success($result, $params);
}

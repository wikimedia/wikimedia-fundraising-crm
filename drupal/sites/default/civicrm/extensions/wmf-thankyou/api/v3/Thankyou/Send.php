<?php
use CRM_WmfThankyou_ExtensionUtil as E;

/**
 * Thankyou.Send API specification (optional)
 * This is used for documentation and validation.
 *
 * @param array $spec description of fields supported by this API call
 *
 * @see https://docs.civicrm.org/dev/en/latest/framework/api-architecture/
 */
function _civicrm_api3_thankyou_send_spec(&$spec) {
  $spec['contribution_id'] = [
    'type' => CRM_Utils_Type::T_INT,
    'title' => ts('Contribution ID'),
    'api.required' => TRUE,
  ];
}

/**
 * Thankyou.Send API
 *
 * @param array $params
 *
 * @return array
 *   API result descriptor
 *
 * @throws \Civi\WMFException\WMFException
 * @throws \CRM_Core_Exception
 *
 * @see civicrm_api3_create_success
 */
function civicrm_api3_thankyou_send($params) {

  if (thank_you_for_contribution($params['contribution_id'], TRUE, $params['template'] ?? NULL) === FALSE) {
    throw new CRM_Core_Exception('Thank you failed.');
  }
  $contribution = civicrm_api3('Contribution', 'getsingle', ['id' => $params['contribution_id']]);
  if (empty($contribution['thankyou_date'])) {
    throw new CRM_Core_Exception('Thank you failed.');
  }
  return civicrm_api3_create_success(1);
}

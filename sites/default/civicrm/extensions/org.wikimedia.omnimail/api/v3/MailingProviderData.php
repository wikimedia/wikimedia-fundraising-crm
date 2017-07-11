<?php

/**
 * MailingProviderData.create API specification (optional)
 * This is used for documentation and validation.
 *
 * @param array $spec description of fields supported by this API call
 * @return void
 * @see http://wiki.civicrm.org/confluence/display/CRMDOC/API+Architecture+Standards
 */
function _civicrm_api3_mailing_provider_data_create_spec(&$spec) {
  // $spec['some_parameter']['api.required'] = 1;
}

/**
 * MailingProviderData.create API
 *
 * @param array $params
 * @return array API result descriptor
 * @throws API_Exception
 */
function civicrm_api3_mailing_provider_data_create($params) {
  return _civicrm_api3_basic_create(_civicrm_api3_get_BAO(__FUNCTION__), $params);
}

/**
 * MailingProviderData.delete API
 *
 * @param array $params
 * @return array API result descriptor
 * @throws API_Exception
 */
function civicrm_api3_mailing_provider_data_delete($params) {
  return _civicrm_api3_basic_delete(_civicrm_api3_get_BAO(__FUNCTION__), $params);
}

/**
 * MailingProviderData.get API
 *
 * @param array $params
 * @return array API result descriptor
 * @throws API_Exception
 */
function civicrm_api3_mailing_provider_data_get($params) {
  $bao = new CRM_Omnimail_BAO_MailingProviderData();
  _civicrm_api3_dao_set_filter($bao, $params, TRUE);
  $bao->selectAdd('CONCAT(contact_identifier, mailing_identifier, recipient_action_datetime) as id');
  return civicrm_api3_create_success(_civicrm_api3_dao_to_array($bao, $params, FALSE, 'MailingProviderData'), $params, 'MailingProviderData', 'get');
}

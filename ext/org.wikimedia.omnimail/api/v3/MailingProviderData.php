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
 * @throws CRM_Core_Exception
 */
function civicrm_api3_mailing_provider_data_create($params) {
  return _civicrm_api3_basic_create(_civicrm_api3_get_BAO(__FUNCTION__), $params);
}

/**
 * MailingProviderData.delete API
 *
 * @param array $params
 * @return array API result descriptor
 * @throws CRM_Core_Exception
 */
function civicrm_api3_mailing_provider_data_delete($params) {
  return _civicrm_api3_basic_delete(_civicrm_api3_get_BAO(__FUNCTION__), $params);
}

/**
 * MailingProviderData.get API
 *
 * @param array $params
 * @return array API result descriptor
 * @throws CRM_Core_Exception
 */
function civicrm_api3_mailing_provider_data_get($params) {
  CRM_Core_DAO::disableFullGroupByMode();
  $sql = CRM_Utils_SQL_Select::fragment();
  $sql->select('CONCAT(contact_identifier, mailing_identifier, recipient_action_datetime, event_type) as id');
  $result = civicrm_api3_create_success(_civicrm_api3_basic_get('CRM_Omnimail_BAO_MailingProviderData', $params, FALSE, 'MailingProviderData', $sql, FALSE), $params, 'MailingProviderData', 'get');
  CRM_Core_DAO::reenableFullGroupByMode();
  return $result;
}

/**
 * Metadata for MailingProviderData.get API
 *
 * @param array $params
 *
 * @throws CRM_Core_Exception
 */
function _civicrm_api3_mailing_provider_data_get_spec(&$params) {
  $params['mailing_identifier']['FKClassName'] = 'CRM_Mailing_BAO_Mailing';
  $params['mailing_identifier']['FKApiName'] = 'Mailing';
  $params['mailing_identifier']['FKKeyColumn'] = 'hash';
}

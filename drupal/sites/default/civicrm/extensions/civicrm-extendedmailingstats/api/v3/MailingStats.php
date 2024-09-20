<?php

/**
 * MailingStats.create API specification (optional)
 * This is used for documentation and validation.
 *
 * @param array $spec description of fields supported by this API call
 * @return void
 * @see http://wiki.civicrm.org/confluence/display/CRMDOC/API+Architecture+Standards
 */
function _civicrm_api3_mailing_stats_create_spec(&$spec) {
  $spec['mailing_id']['api.required'] = 1;
}

/**
 * MailingStats.create API
 *
 * @param array $params
 * @return array API result descriptor
 * @throws \CRM_Core_Exception
 */
function civicrm_api3_mailing_stats_create($params) {
  return _civicrm_api3_basic_create(_civicrm_api3_get_BAO(__FUNCTION__), $params);
}

/**
 * MailingStats.delete API
 *
 * @param array $params
 * @return array API result descriptor
 * @throws CRM_Core_Exception
 */
function civicrm_api3_mailing_stats_delete($params) {
  return _civicrm_api3_basic_delete(_civicrm_api3_get_BAO(__FUNCTION__), $params);
}

/**
 * MailingStats.get API
 *
 * @param array $params
 * @return array API result descriptor
 * @throws CRM_Core_Exception
 */
function civicrm_api3_mailing_stats_get($params) {
  return _civicrm_api3_basic_get(_civicrm_api3_get_BAO(__FUNCTION__), $params);
}

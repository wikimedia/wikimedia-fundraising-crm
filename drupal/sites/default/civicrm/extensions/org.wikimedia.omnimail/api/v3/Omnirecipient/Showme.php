<?php
require_once 'api/v3/ShowmeUtils.php';

/**
 * phone.Showme API specification (optional)
 * This is used for documentation and validation.
 *
 * @param array $spec description of fields supported by this API call
 * @return void
 * @see http://wiki.civicrm.org/confluence/display/CRMDOC/API+Architecture+Standards
 */
function _civicrm_api3_omnirecipient_showme_spec(&$spec) {
}

/**
 * phone.Showme API
 *
 * The point of this api is to get all data about a phone with some prefiltering
 * and formatting.
 *
 * @param array $params
 * @return array API result descriptor
 * @see civicrm_api3_create_success
 * @see civicrm_api3_create_error
 * @throws CRM_Core_Exception
 */
function civicrm_api3_omnirecipient_showme($params) {
  $result = _civicrm_api3_generic_showme(['entity' => 'MailingProviderData', 'params' => $params]);
  if (empty($result['count'])) {
    // Add 'something' to showme so it will still call forgetme.
    $result['count'] = 1;
    $result['values'][0] = ts('No bulk emails have been sent');
  }
  return $result;
}


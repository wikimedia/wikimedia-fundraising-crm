<?php
require_once 'api/v3/Generic/Showme.php';

/**
 * phone.Showme API specification (optional)
 * This is used for documentation and validation.
 *
 * @param array $spec description of fields supported by this API call
 * @return void
 * @see http://wiki.civicrm.org/confluence/display/CRMDOC/API+Architecture+Standards
 */
function _civicrm_api3_phone_showme_spec(&$spec) {
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
 * @throws API_Exception
 */
function civicrm_api3_phone_showme($params) {
  $params['internal_fields'] = ['phone_numeric', 'is_billing', 'contact_id'];
  return _civicrm_api3_generic_showme(['entity' => 'Phone', 'params' => $params]);
}


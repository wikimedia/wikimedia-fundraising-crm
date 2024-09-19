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
function _civicrm_api3_website_showme_spec(&$spec) {}

/**
 * website.Showme API
 *
 * The point of this api is to get all data about a website with some prefiltering
 * and formatting.
 *
 * @param array $params
 * @return array API result descriptor
 * @see civicrm_api3_create_success
 * @see civicrm_api3_create_error
 * @throws CRM_Core_Exception
 */
function civicrm_api3_website_showme($params) {
  $params['internal_fields'] = ['contact_id'];
  return _civicrm_api3_generic_showme(['entity' => 'Website', 'params' => $params]);
}


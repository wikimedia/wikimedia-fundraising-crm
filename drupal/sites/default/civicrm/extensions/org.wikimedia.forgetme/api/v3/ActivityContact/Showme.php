<?php
use CRM_showme_ExtensionUtil as E;
require_once 'api/v3/ShowmeUtils.php';

/**
 * activity.showme API specification (optional)
 * This is used for documentation and validation.
 *
 * @param array $spec description of fields supported by this API call
 * @return void
 * @see http://wiki.civicrm.org/confluence/display/CRMDOC/API+Architecture+Standards
 */
function _civicrm_api3_activity_contact_showme_spec(&$spec) {
}

/**
 * activity.showme API
 *
 * The point of this api is to get all data about a activity with some prefiltering
 * and formatting.
 *
 * @param array $params
 * @return array API result descriptor
 * @see civicrm_api3_create_success
 * @see civicrm_api3_create_error
 * @throws CRM_Core_Exception
 */
function civicrm_api3_activity_contact_showme($params) {
  // @todo - actually 'show' activities - be careful of ones with multiple contacts involved.
  return _civicrm_api3_generic_showme(['entity' => 'ActivityContact', 'params' => $params]);
}


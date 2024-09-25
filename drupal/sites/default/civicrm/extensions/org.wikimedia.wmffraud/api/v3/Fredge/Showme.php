<?php
use CRM_Forgetme_ExtensionUtil as E;
require_once 'api/v3/ShowmeUtils.php';

/**
 * fredge.Showme API specification (optional)
 * This is used for documentation and validation.
 *
 * @param array $spec description of fields supported by this API call
 * @return void
 * @see http://wiki.civicrm.org/confluence/display/CRMDOC/API+Architecture+Standards
 */
function _civicrm_api3_fredge_showme_spec(&$spec) {
  $spec['contact_id']['api.required'] = TRUE;
}

/**
 * fredge.Showme API
 *
 * The point of this api is to get all data about a fredge with some prefiltering
 * and formatting.
 *
 * @param array $params
 * @return array API result descriptor
 * @see civicrm_api3_create_success
 * @throws \CRM_Core_Exception
 */
function civicrm_api3_fredge_showme($params) {
  $params['internal_fields'] = ['id', 'contribution_tracking_id', 'server'];
  return _civicrm_api3_generic_showme(['entity' => 'Fredge', 'params' => $params]);
}


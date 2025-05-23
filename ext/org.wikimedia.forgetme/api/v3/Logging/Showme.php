<?php
require_once 'api/v3/ShowmeUtils.php';

/**
 * logging.Showme API specification (optional)
 * This is used for documentation and validation.
 *
 * @param array $spec description of fields supported by this API call
 * @return void
 * @see http://wiki.civicrm.org/confluence/display/CRMDOC/API+Architecture+Standards
 */
function _civicrm_api3_logging_showme_spec(&$spec) {
  $spec['contact_id']['title'] = ts('Contact ID');
  $spec['contact_id']['api.required'] = TRUE;
}

/**
 * logging.Showme API
 *
 * The point of this api is to get all data about logging for a contact, with some prefiltering
 * and formatting.
 *
 * @param array $params
 * @return array API result descriptor
 * @see civicrm_api3_create_success
 * @see civicrm_api3_create_error
 * @throws CRM_Core_Exception
 */
function civicrm_api3_logging_showme($params) {
  if (is_numeric($params['contact_id'])) {
    $params['contact_id'] = ['IN' => [$params['contact_id']]];
  }
  $showMe = new CRM_Forgetme_LoggingShowme('Logging', $params, []);
  $entities =  $showMe->getDisplayValues();
  $return = civicrm_api3_create_success($entities, $params);
  $return['metadata'] = $showMe->getMetadata();
  $return['showme'] = $showMe->getDisplayTiles();
  return $return;
}



<?php
use CRM_Forgetme_ExtensionUtil as E;

/**
 * Fredge.Forgetme API specification (optional)
 * This is used for documentation and validation.
 *
 * @param array $spec description of fields supported by this API call
 * @return void
 * @see http://wiki.civicrm.org/confluence/display/CRMDOC/API+Architecture+Standards
 */
function _civicrm_api3_fredge_forgetme_spec(&$spec) {
  $spec['contact_id']['api.required'] = 1;
}

/**
 * fredge.obfuscate API
 *
 * The point of this api is to get all data about a fredge with some prefiltering
 * and formatting.
 *
 * @param array $params
 * @return array API result descriptor
 * @see civicrm_api3_create_success
 * @see civicrm_api3_create_error
 * @throws API_Exception
 */
function civicrm_api3_fredge_forgetme($params) {
  $fredges = civicrm_api3('Fredge', 'get', $params)['values'];
  if (empty($fredges)) {
    return civicrm_api3_create_success([], $params);
  }
  $dbs = wmf_civicrm_get_dbs();
  $dbs->push('fredge');
  db_update('payments_fraud')
    ->fields(array(
      'user_ip' => NULL,
    ))
    ->condition('id', array_keys($fredges), 'IN')
    ->execute();
  $dbs->push('default');
  return civicrm_api3_create_success($fredges, $params);
}

<?php
use CRM_Forgetme_ExtensionUtil as E;

/**
 * logging.Forgetme API specification (optional)
 * This is used for documentation and validation.
 *
 * @param array $spec description of fields supported by this API call
 * @return void
 * @see http://wiki.civicrm.org/confluence/display/CRMDOC/API+Architecture+Standards
 */
function _civicrm_api3_logging_forgetme_spec(&$spec) {
  $spec['contact_id']['api.required'] = 1;
}

/**
 * logging.obfuscate API
 *
 * The point of this api is to get all data about a logging with some prefiltering
 * and formatting.
 *
 * @param array $params
 * @return array API result descriptor
 * @see civicrm_api3_create_success
 * @see civicrm_api3_create_error
 * @throws API_Exception
 */
function civicrm_api3_logging_forgetme($params) {
  $loggings = civicrm_api3('logging', 'showme', $params)['values'];
  if (empty($loggings)) {
    return civicrm_api3_create_success([], $params);
  }
  $deletions = [];
  $entitiesToDelete = CRM_Forgetme_Metadata::getEntitiesToDelete();
  foreach ($loggings as $logging) {
    if (isset($entitiesToDelete[$logging['table']])) {
      $string = CRM_Core_DAO::composeQuery('(log_conn_id = %1 AND id = %2)', [
        1 => [$logging['log_conn_id'], 'String'],
        2 => [$logging['id'], 'Integer'],
      ]);
      $deletions[$logging['table']][$string] = $string;
    }
  }
  foreach ($deletions as $table => $deletion) {
    CRM_Core_DAO::executeQuery("DELETE FROM log_{$table} WHERE " . implode(' OR ', $deletion));
  }
  return civicrm_api3_create_success($loggings, $params);
}

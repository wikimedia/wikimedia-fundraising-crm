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
 * @throws CRM_Core_Exception
 */
function civicrm_api3_logging_forgetme($params) {
  if (is_numeric($params['contact_id'])) {
    $params['contact_id'] = ['IN' => [$params['contact_id']]];
  }
  $loggings = civicrm_api3('logging', 'showme', $params)['values'];
  if (empty($loggings)) {
    return civicrm_api3_create_success([], $params);
  }
  $deletions = [];
  $activities = [];
  $entitiesToDelete = array_merge(CRM_Forgetme_Metadata::getEntitiesToDelete(), CRM_Forgetme_Metadata::getContactExtendingCustomTables());
  foreach ($loggings as $logging) {
    if (isset($entitiesToDelete[$logging['table']])) {
      $string = CRM_Core_DAO::composeQuery('(log_conn_id = %1 AND id = %2)', [
        1 => [$logging['log_conn_id'], 'String'],
        2 => [$logging['id'], 'Integer'],
      ]);
      $deletions[$logging['table']][$string] = $string;
      if ($logging['table'] === 'civicrm_activity_contact') {
        $activities[$logging['activity_id']] = $logging['activity_id'];
      }
    }
  }
  foreach ($deletions as $table => $deletion) {
    CRM_Core_DAO::executeQuery("DELETE FROM log_{$table} WHERE " . implode(' OR ', $deletion));
  }

  if (!empty($activities)) {
    $nonKeeperContactIds = _civicrm_api3_showme_get_get_user_contact_ids();
    $nonKeeperContactIds = array_merge($nonKeeperContactIds, $params['contact_id']['IN']);

    $activitiesToKeep = CRM_Core_DAO::singleValueQuery(
      'SELECT group_concat(activity_id) FROM log_civicrm_activity_contact WHERE activity_id IN (' . implode(',', $activities) . ') AND contact_id NOT IN (' . implode(',', $nonKeeperContactIds) . ')'
    );

    $activitiesToDelete = array_diff($activities, explode(',', $activitiesToKeep ?? ''));
    if (!empty($activitiesToDelete)) {
      CRM_Core_DAO::executeQuery("DELETE FROM log_civicrm_activity WHERE id IN (" . implode(',', $activitiesToDelete) . ")");
    }
  }

  $fieldsToForget = CRM_Forgetme_Metadata::getMetadataForEntity('Contact', 'forget_fields');
  $updateSQLs = [];
  foreach ($fieldsToForget as $fieldName => $spec) {
    $updateSQLs[] = "$fieldName = NULL";
  }
  $whereClause = CRM_Core_DAO::createSQLFilter('id', $params['contact_id']);
  CRM_Core_DAO::executeQuery('UPDATE log_civicrm_contact SET ' . implode(',', $updateSQLs) . " WHERE $whereClause");

  return civicrm_api3_create_success($loggings, $params);
}

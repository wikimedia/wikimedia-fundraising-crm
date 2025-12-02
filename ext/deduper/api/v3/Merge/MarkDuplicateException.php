<?php

/**
 * Get information about conflicts.
 *
 * This is intended as a transitional / experimental function - I'm working
 * to improve api access upstream.
 *
 * @param array $spec description of fields supported by this API call
 *
 * @see http://wiki.civicrm.org/confluence/display/CRMDOC/API+Architecture+Standards
 */
function _civicrm_api3_merge_mark_duplicate_exceptionspec(&$spec) {
  $spec['rule_group_id']['api.required'] = 1;
  $spec['group_id'] =['title' => ts('CiviCRM Group')];
  $spec['criteria'] = ['title' => ts('Dedupe criteria')];
  $spec['search_limit'] = ['title' => ts('Limit of contacts to find matches for'), 'api.required' => TRUE];
}

/**
 * Get cache match info
 *
 * This is intended as a transitional / experimental function - I'm working
 * to improve api access upstream.
 *
 * This function retrieves cached information about merge attempts.
 *
 * @param array $params
 *
 * @return array API result descriptor
 *
 * @throws \CRM_Core_Exception
 */
function civicrm_api3_merge_mark_duplicate_exception($params) {
  // @todo be careful if we later enable refillCache since there is no limit...
  $pairs = CRM_Dedupe_Merger::getDuplicatePairs($params['rule_group_id'], NULL, FALSE, 0, FALSE, TRUE, $params['criteria'], $params['check_permissions'] ?? NULL, $params['search_limit']);
  foreach ($pairs as $pair) {
    civicrm_api3('Exception', 'create', ['contact_id1' => $pair['dstID'], 'contact_id2' => $pair['srcID']]);
    CRM_Core_DAO::executeQuery('
      DELETE FROM civicrm_prevnext_cache
      WHERE (entity_id1 = %1 AND entity_id2 = %2)
      OR (entity_id1 = %2 AND entity_id2 = %1)
    ', [1 => [$pair['srcID'], 'Integer'], 2 => [$pair['dstID'], 'Integer']]);
  }
  return civicrm_api3_create_success($pairs);
}

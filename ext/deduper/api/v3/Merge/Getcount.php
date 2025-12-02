<?php

/**
 * Get duplicates metadata.
 *
 * This is intended as a transitional / experimental function - I'm working
 * to improve api access upstream.
 *
 * @param array $spec description of fields supported by this API call
 *
 * @see http://wiki.civicrm.org/confluence/display/CRMDOC/API+Architecture+Standards
 */
function _civicrm_api3_merge_getcount_spec(&$spec) {
  $spec['rule_group_id']['api.required'] = 1;
  $spec['group_id'] =['title' => ts('CiviCRM Group')];
  $spec['criteria'] = ['title' => ts('Dedupe criteria')];
  $spec['search_limit'] = ['title' => ts('Limit of contacts to find matches for'), 'api.required' => TRUE];

}

/**
 * Get duplicates metadata.
 *
 * This is intended as a transitional / experimental function - I'm working
 * to improve api access upstream.
 *
 * @param array $params
 * @return int
 *
 * @throws \CRM_Core_Exception
 */
function civicrm_api3_merge_getcount($params) {
  $cacheKeyString = CRM_Dedupe_Merger::getMergeCacheKeyString($params['rule_group_id'], $params['group_id'] ?? NULL, $params['criteria'], $params['check_permissions'] ?? NULL, $params['search_limit']);
  // @todo - pretty sure these joins are no longer required as we remove from the cache once they have
  // been marked duplicates now.
  return CRM_Core_DAO::singleValueQuery("
    SELECT count(*) FROM civicrm_prevnext_cache pn
    LEFT JOIN civicrm_dedupe_exception de
    ON (pn.entity_id1 = de.contact_id1 AND pn.entity_id2 = de.contact_id2 )
    LEFT JOIN civicrm_dedupe_exception de2
    ON (pn.entity_id2 = de.contact_id1 AND pn.entity_id1 = de.contact_id2 )
    WHERE (pn.cacheKey = %1 OR pn.cacheKey = %2) AND de.id IS NULL AND de2.id IS NULL
", [1 => [$cacheKeyString, 'String'], 2 => [$cacheKeyString . '_conflicts', 'String']]);
}

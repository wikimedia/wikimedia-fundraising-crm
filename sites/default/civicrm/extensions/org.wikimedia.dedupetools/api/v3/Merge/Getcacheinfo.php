<?php

/**
 * Get information about cached matches
 *
 * This is intended as a transitional / experimental function - I'm working
 * to improve api access upstream.
 *
 * @param array $spec description of fields supported by this API call
 *
 * @see http://wiki.civicrm.org/confluence/display/CRMDOC/API+Architecture+Standards
 */
function _civicrm_api3_merge_getcacheinfo_spec(&$spec) {
  $spec['rule_group_id']['api.required'] = 1;
  $spec['group_id'] =['title' => ts('CiviCRM Group')];
  $spec['criteria'] = ['title' => ts('Dedupe criteria')];
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
 * @return array API result descriptor
 *
 * @throws API_Exception
 * @throws CiviCRM_API3_Exception
 */
function civicrm_api3_merge_getcacheinfo($params) {
  $cacheKeyString = CRM_Dedupe_Merger::getMergeCacheKeyString(
    $params['rule_group_id'],
    CRM_Utils_Array::value('group_id', $params),
    CRM_Utils_Array::value('criteria', $params, []),
    CRM_Utils_Array::value('check_permissions', $params)
  );
  $stats = CRM_Dedupe_Merger::getMergeStats($cacheKeyString);
  $options = _civicrm_api3_get_options_from_params($params);
  $matches = CRM_Dedupe_Merger::getDuplicatePairs($params['rule_group_id'], CRM_Utils_Array::value('group_id', $params), FALSE, $options['limit'], FALSE, '', TRUE, CRM_Utils_Array::value('criteria', $params, []), TRUE);
  // Skipped rows are in a format similar to the DB - for cached rows we re-index by the primary contact
  // so we wind up with an array of matches for that contact. This format seems like the one we should
  // work towards - but there are challenges as we might find it hard to cull merged ones.
  // so for now working with both.
  $skippedRows = $cachedRows = [];
  foreach ($matches as $match) {
    $match['main_id'] = $match['dstID'];
    $match['other_id'] = $match['srcID'];
    $conflictString = CRM_Utils_Array::value('conflicts', $match, []);
    $fields = civicrm_api3('Contact', 'getfields',[])['values'];
    $titleMapping = [];
    foreach ($fields as $key => $field) {
      $titleMapping[$field['title']] = $key;
    }
    // ug did they HAVE to store as a string....
    $parts = explode("',", $conflictString);
    foreach ($parts as $conflict) {
      list($key, $values)  = explode(": '", $conflict, 2);
      $key = $titleMapping[$key];
      $separateContacts = explode("' vs. '", $values);
      $conflicts[$key] = [
        $match['main_id'] => $separateContacts[0],
        $match['other_id'] => $separateContacts[1],
      ];
    }
    $match['conflicts'] = $conflicts;
    $skippedRows[] = $match;
  }

  return civicrm_api3_create_success([[
    'key' => $cacheKeyString,
    'stats' => $stats,
    'skipped' => $skippedRows,
    'found' => civicrm_api3('Merge', 'getcount', $params),
  ]]);
}

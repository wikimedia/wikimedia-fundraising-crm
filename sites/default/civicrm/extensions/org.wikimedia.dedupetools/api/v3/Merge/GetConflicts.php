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
function _civicrm_api3_merge_get_conflicts_spec(&$spec) {
  $spec['to_keep_id']['api.required'] = 1;
  $spec['to_remove_id']['api.required'] = 1;
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
function civicrm_api3_merge_get_conflicts($params) {
  $conflicts = [];
  // Generate var $migrationInfo. The variable structure is exactly same as
  // $formValues submitted during a UI merge for a pair of contacts.
  $rowsElementsAndInfo = CRM_Dedupe_Merger::getRowsElementsAndInfo($params['to_keep_id'], $params['to_remove_id'], CRM_Utils_Array::value('check_permissions', TRUE));
  // add additional details that we might need to resolve conflicts
  $rowsElementsAndInfo['migration_info']['main_details'] = &$rowsElementsAndInfo['main_details'];
  $rowsElementsAndInfo['migration_info']['other_details'] = &$rowsElementsAndInfo['other_details'];
  $rowsElementsAndInfo['migration_info']['rows'] = &$rowsElementsAndInfo['rows'];

  CRM_Dedupe_Merger::skipMerge($params['to_keep_id'], $params['to_remove_id'], $rowsElementsAndInfo['migration_info'], 'safe', $conflicts);
  $result = [];
  foreach (array_keys($conflicts) as $conflict) {
    $field = substr($conflict, 5);
    $result[$field] = [
      $params['to_keep_id'] => $rowsElementsAndInfo['main_details'][$field],
      $params['to_remove_id'] => $rowsElementsAndInfo['other_details'][$field],
    ];
  }
  return civicrm_api3_create_success([$result]);
}

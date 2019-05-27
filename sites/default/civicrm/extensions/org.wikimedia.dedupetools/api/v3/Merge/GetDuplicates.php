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
function _civicrm_api3_merge_get_duplicates_spec(&$spec) {
  $spec['rule_group_id']['api.required'] = 1;
  $spec['group_id'] =['title' => ts('CiviCRM Group')];
  $spec['criteria'] = ['title' => ts('Dedupe criteria')];
  $spec['search_limit'] = [
    'title' => ts('Number of contacts to look for matches for.'),
    'type' => CRM_Utils_Type::T_INT,
    'api.default' => Civi::settings()->get('dedupe_default_limit'),
  ];
}

/**
 * Get duplicates metadata.
 *
 * This is intended as a transitional / experimental function - I'm working
 * to improve api access upstream.
 *
 * @param array $params
 * @return array API result descriptor
 *
 * @throws API_Exception
 * @throws CiviCRM_API3_Exception
 */
function civicrm_api3_merge_get_duplicates($params) {
  $options = _civicrm_api3_get_options_from_params($params);
  $dupePairs = CRM_Dedupe_Merger::getDuplicatePairs($params['rule_group_id'], NULL, TRUE, $options['limit'], FALSE, '', TRUE, $params['criteria'], CRM_Utils_Array::value('check_permissions', $params), $params['search_limit']);
  return civicrm_api3_create_success($dupePairs);
}

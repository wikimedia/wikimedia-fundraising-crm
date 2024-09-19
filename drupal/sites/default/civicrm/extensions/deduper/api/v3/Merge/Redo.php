<?php

/**
 * Merge redo spec
 *
 * @param array $spec description of fields supported by this API call
 *
 * @see http://wiki.civicrm.org/confluence/display/CRMDOC/API+Architecture+Standards
 */
function _civicrm_api3_merge_redo_spec(&$spec) {
  $spec['contact_id']['api.required'] = 1;
}

/**
 * Redo merge.
 *
 * This function undeletes a merged contact and remerges it. This could be used to
 * fix a merge where a deadlock caused some data to be left behind.
 *
 * @param array $params
 * @return array API result descriptor
 * @throws \CRM_Core_Exception
 */
function civicrm_api3_merge_redo($params) {
  $newContact = civicrm_api3('Contact', 'getmergedto', [
    'contact_id' => $params['contact_id'],
    'sequential' => 1,
  ]);
  if ($newContact['count'] == 0) {
    throw new CRM_Core_Exception(ts('Contact not re-mergeable'));
  }
  civicrm_api3('Contact', 'create', ['id' => $params['contact_id'], 'is_deleted' => 0]);
  return civicrm_api3('Contact', 'merge', ['to_remove_id' => $params['contact_id'], 'to_keep_id' => $newContact['id']]);
}

<?php
use CRM_Forgetme_ExtensionUtil as E;

/**
 * activity.forgetme API specification (optional)
 * This is used for documentation and validation.
 *
 * @param array $spec description of fields supported by this API call
 * @return void
 * @see http://wiki.civicrm.org/confluence/display/CRMDOC/API+Architecture+Standards
 */
function _civicrm_api3_activity_contact_forgetme_spec(&$spec) {
}

/**
 * activity.forgetme API
 *
 * The point of this api is to get all data about a activity with some prefiltering
 * and formatting.
 *
 * @param array $params
 * @return array API result descriptor
 * @see civicrm_api3_create_success
 * @see civicrm_api3_create_error
 * @throws API_Exception
 */
function civicrm_api3_activity_contact_forgetme($params) {
  $filters = CRM_Forgetme_Metadata::getMetadataForEntity('ActivityContact', 'forget_filters');
  $params = array_merge($params, $filters);
  $activityContactRecords = civicrm_api3('ActivityContact', 'get', $params)['values'];
  if (empty($activityContactRecords)) {
    return civicrm_api3_create_success([], $params);
  }
  $activities = [];
  foreach ($activityContactRecords as $activityContactRecord) {
    $activities[$activityContactRecord['activity_id']] = $activityContactRecord['activity_id'];
  }

  static $ufMatches = [];
  if (empty($ufMatches)) {
    // Currently only 143 - probably this will only be hit once so static caching doesn't mean that much.
    $result = civicrm_api3('UFMatch', 'get', [
      'return' => 'contact_id',
      'options' => ['limit' => 0]
    ])['values'];
    $ufMatches = array_keys($result);
  }

  $activitiesToKeep = civicrm_api3('ActivityContact', 'get', [
    'activity_id' => ['IN' => $activities],
    'contact_id' => ['NOT IN' => array_merge($ufMatches, [$params['contact_id']])],
    'return' => 'activity_id',
  ]);
  foreach ($activitiesToKeep['values'] as $activityToKeep) {
    $activityID = $activityToKeep['activity_id'];
    if (isset($activities[$activityID])) {
      unset($activities[$activityID]);
    }
  }

  foreach ($activityContactRecords as $activityContactRecord) {
    if (!isset($activities[$activityContactRecord['activity_id']])) {
      civicrm_api3('ActivityContact', 'delete', ['id' => $activityContactRecord['id']]);
    }
    else {
      civicrm_api3('Activity', 'delete', ['id' => $activityContactRecord['activity_id']]);
    }
  }

  return civicrm_api3_create_success($activityContactRecord, $params);
}


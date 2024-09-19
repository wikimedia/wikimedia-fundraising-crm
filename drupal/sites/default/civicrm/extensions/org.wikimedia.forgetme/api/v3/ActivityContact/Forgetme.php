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
 * @throws CRM_Core_Exception
 */
function civicrm_api3_activity_contact_forgetme($params) {
  $filters = CRM_Forgetme_Metadata::getMetadataForEntity('ActivityContact', 'forget_filters');
  $params = array_merge($params, $filters);
  if (is_numeric($params['contact_id'])) {
    $params['contact_id'] = ['IN' => [$params['contact_id']]];
  }
  // Do activities with parents first to avoid trying to delete already deleted activities.
  $params['options']['sort'] = 'activity_id.parent_id DESC';
  $activityContactRecords = civicrm_api3('ActivityContact', 'get', $params)['values'];
  if (empty($activityContactRecords)) {
    return civicrm_api3_create_success([], $params);
  }
  $activities = _civicrm_api3_activity_forget_getActivitiesToDelete($params['contact_id']['IN'], $activityContactRecords);

  foreach ($activityContactRecords as $activityContactRecord) {
    // Where the activity is associated with many contacts we only want to delink the forgotten contact
    // without deleting the activity.
    if (!isset($activities[$activityContactRecord['activity_id']])) {
      civicrm_api3('ActivityContact', 'delete', ['id' => $activityContactRecord['id']]);
    }
  }
  foreach ($activities as $activityID) {
    civicrm_api3('Activity', 'delete', ['id' => $activityID]);
  }

  return civicrm_api3_create_success($activityContactRecord, $params);
}

/**
 * Get a list of activities that can be deleted when forgetting the given contact IDs.
 *
 * We have a list of activity contact records. Where other the activity involves multiple contacts
 * we want to keep the activity and just delink the contact.
 *
 * If the activity only affects the contacts to be forgotten it can be fully deleted. Note
 * that where the other contacts involved have user ids then their involvement is understood to
 * be part of maintaining the contact record so we check for activities that have no other donors
 * involved.
 *
 * @param array $contactIDsToForget
 * @param array $activityContactRecords
 *
 * @return array
 *   Array of activity ids to fully delete.
 */
function _civicrm_api3_activity_forget_getActivitiesToDelete($contactIDsToForget, $activityContactRecords) {
  $activities = [];
  foreach ($activityContactRecords as $activityContactRecord) {
    $activities[$activityContactRecord['activity_id']] = $activityContactRecord['activity_id'];
  }

  $ufMatches = _civicrm_api3_showme_get_get_user_contact_ids();

  $activitiesToKeep = civicrm_api3('ActivityContact', 'get', [
    'activity_id' => ['IN' => $activities],
    'contact_id' => ['NOT IN' => array_merge($ufMatches, $contactIDsToForget)],
    'return' => 'activity_id',
  ]);
  foreach ($activitiesToKeep['values'] as $activityToKeep) {
    $activityID = $activityToKeep['activity_id'];
    if (isset($activities[$activityID])) {
      unset($activities[$activityID]);
    }
  }
  return $activities;
}


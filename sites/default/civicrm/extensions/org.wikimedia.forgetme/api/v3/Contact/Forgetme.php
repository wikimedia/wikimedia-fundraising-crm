<?php
use CRM_Forgetme_ExtensionUtil as E;
require_once 'api/v3/ShowmeUtils.php';

/**
 * Contact.Showme API specification (optional)
 * This is used for documentation and validation.
 *
 * @param array $spec description of fields supported by this API call
 * @return void
 * @see http://wiki.civicrm.org/confluence/display/CRMDOC/API+Architecture+Standards
 */
function _civicrm_api3_contact_forget_spec(&$spec) {
  $spec['id']['api.required'] = 1;
}

/**
 * Contact.forgetme API
 *
 * The point of this api is to get all data about a contact with some prefiltering
 * and formatting.
 *
 * @param array $params
 * @return array API result descriptor
 * @see civicrm_api3_create_success
 * @see civicrm_api3_create_error
 * @throws API_Exception
 */
function civicrm_api3_contact_forgetme($params) {
  $result = [];
  $entitiesToDelete = ['phone', 'email', 'website', 'im'];
  foreach ($entitiesToDelete as $entityToDelete) {
    $delete = civicrm_api3($entityToDelete, 'showme', ['contact_id' => $params['id'], "api.{$entityToDelete}.delete" => 1]);
    if ($delete['count']) {
      foreach ($delete['showme'] as $id => $string) {
        $result[$entityToDelete . $id] = $string;
      }
    }
  }

  $forgets = _civicrm_api3_showme_get_entities_with_action('forgetme');
  foreach ($forgets as $forgettableEntity) {
    if ($forgettableEntity !== 'Contact') {
      $delete = civicrm_api3($forgettableEntity, 'showme', ['contact_id' => $params['id']]);
      if ($delete['count']) {
        civicrm_api3($forgettableEntity, 'forgetme', ['contact_id' => $params['id']]);
        foreach ($delete['showme'] as $id => $string) {
          $result[$forgettableEntity . $id] = $string;
        }
      }
    }
  }

  $loggedInUser = CRM_Core_Session::getLoggedInContactID();

  civicrm_api3('Activity', 'create', [
    'activity_type_id' => 'forget_me',
    'subject' => (empty($params['reference'])) ? ts('Privacy request') : $params['reference'] . ' ' . ts('Privacy Request'),
    'target_contact_id' => $params['id'],
    'source_contact_id' => ($loggedInUser ? : $params['id']),
  ]);
  return civicrm_api3_create_success($result, $params);
}

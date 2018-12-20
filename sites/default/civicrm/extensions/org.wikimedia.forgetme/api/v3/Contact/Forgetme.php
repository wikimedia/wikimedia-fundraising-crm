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
  $spec['id']['type'] = CRM_Utils_Type::T_INT;
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

  // Gather emails to pass on to other actions - we currently only need primary email downstream as we
  // expect to only worry about deleting that in Silverpop but doing filtering in late functions makes sense.
  // I did think about putting this later & using $contactIDsToForget but current conversation is to focus on
  // current is_primary
  $emails = civicrm_api3('Email', 'get', [
    'contact_id' => $params['id'],
    'return' => ['email', 'is_primary', 'contact_id']
  ])['values'];

  $fieldsToForget = array_merge(
    CRM_Forgetme_Metadata::getMetadataForEntity('Contact', 'forget_fields'),
    CRM_Forgetme_Metadata::getMetadataForEntity('Contact', 'custom_forget_fields')
  );
  $mergees = civicrm_api3('Contact', 'getmergedfrom', ['contact_id' => $params['id']])['values'];
  $contactIDsToForget = array_merge([$params['id']], array_keys($mergees));

  foreach ($fieldsToForget as $fieldName => $fieldSpec) {
    $nullValue = 'null';
    if ($fieldSpec['type'] === CRM_Utils_Type::T_MONEY) {
      $nullValue = 0;
    }
    $forgetParams[$fieldName] = $nullValue;
  }
  foreach ($contactIDsToForget as $contactID) {
    $forgetParams['id'] = $contactID;
    civicrm_api3('Contact', 'create', $forgetParams);
  }

  foreach (_contact_forgetme_get_processable_entities() as $entityToDelete) {
    $deleteParams = ['contact_id' => ['IN' => $contactIDsToForget]];
    $hasForget = TRUE;
    if (!in_array($entityToDelete, CRM_Forgetme_Metadata::getEntitiesToForget())) {
      $deleteParams["api.{$entityToDelete}.delete"] = 1;
      $hasForget = FALSE;
    }
    $delete = civicrm_api3($entityToDelete, 'showme', $deleteParams);
    // @todo fix this hacky exception method here.
    // We need to call Omnirecipient forget regardless of the presence
    // of any Mailing / Omnirecipient entries as we need to message
    // Silverpop about it. We don't like to just hack in an entity name
    // but I have added a test so we can clean up later.
    // Am thinking we need to write the emails to a table & do some follow up before deleting them
    // T212382
    if ($delete['count'] || ($entityToDelete === 'Omnirecipient' && empty($params['no_omnimail_hack']))) {
      if ($hasForget) {
        civicrm_api3($entityToDelete, 'forgetme', [
          'contact_id' => ['IN' => $contactIDsToForget],
          'contact' => ['emails' => $emails]]
        );
      }
      foreach ($delete['showme'] as $id => $string) {
        $result[$entityToDelete . $id] = $string;
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

/**
 * Get an array of all entities with forget actions.
 *
 * We cache this for mild performance gain but it's not clear php caching
 * helps us much as this is not often called multiple times within one php call.
 *
 * However, once we have upgraded & switched to Redis caching we could move this
 * over and probably get more benefit.
 *
 * @return array
 */
function _contact_forgetme_get_processable_entities() {
  $entitiesToDelete = CRM_Forgetme_Metadata::getEntitiesToDelete();
  return array_merge($entitiesToDelete, CRM_Forgetme_Metadata::getEntitiesToForget());
}

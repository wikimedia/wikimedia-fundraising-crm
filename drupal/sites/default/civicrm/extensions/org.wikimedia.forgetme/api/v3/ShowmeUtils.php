<?php
use CRM_Forgetme_ExtensionUtil as E;

/**
 * generic.Showme API
 *
 * The point of this api is to get all data about a generic with some prefiltering
 * and formatting.
 *
 * @param array $params
 * @return array API result descriptor
 * @see civicrm_api3_create_success
 * @see civicrm_api3_create_error
 * @throws CRM_Core_Exception
 */
function _civicrm_api3_generic_showme($apiRequest) {
  $showMe = new CRM_Forgetme_Showme($apiRequest['entity'], $apiRequest['params'], CRM_Utils_Array::value('options', $apiRequest['params'], []));
  if (isset($apiRequest['params']['internal_fields'])) {
    $showMe->setInternalFields($apiRequest['params']['internal_fields']);
  }
  $entities =  $showMe->getDisplayValues();
  $return = civicrm_api3_create_success($entities, $apiRequest['params']);
  $return['metadata'] = $showMe->getMetadata();
  $return['showme'] = $showMe->getDisplayTiles();
  return $return;
}

/**
 * @param $action
 * @param null|array $apiEntities
 *
 * @return array
 */
function _civicrm_api3_showme_get_entities_with_action($action, $apiEntities = NULL) {
  if (!$apiEntities) {
    $apiEntities = civicrm_api3('Entity', 'get', [])['values'];
  }

  foreach ($apiEntities as $key => $entityName) {
    $actions = civicrm_api3($entityName, 'getactions')['values'];
    if (!in_array($action, $actions)) {
      unset($apiEntities[$key]);
    }
  }
  return $apiEntities;
}

/**
 * Get contact ids for actual users.
 *
 * Currently only 143 - probably this will only be hit once so static caching doesn't mean that much.
 * Note that our Redis caching kicks in when we update to CiviCRM 5.4 & we should possibly refactor after that.
 *
 * @return array
 */
function _civicrm_api3_showme_get_get_user_contact_ids() {
  static $ufMatches = [];
  if (empty($ufMatches)) {
    $result = civicrm_api3('UFMatch', 'get', [
      'return' => 'contact_id',
      'options' => ['limit' => 0]
    ])['values'];
    $ufMatches = array_keys($result);
  }
  return $ufMatches;
}

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
 * @throws API_Exception
 */
function _civicrm_api3_generic_showme($apiRequest) {
  $showMe = new CRM_Forgetme_Showme($apiRequest['entity'], $apiRequest['params']);
  $showMe->setInternalFields($apiRequest['params']['internal_fields']);
  $entities =  $showMe->getDisplayValues();
  $return = civicrm_api3_create_success($entities, $apiRequest['params']);
  $return['metadata'] = $showMe->getMetadata();
  $return['showme'] = $showMe->getDisplayTiles();
  return $return;
}

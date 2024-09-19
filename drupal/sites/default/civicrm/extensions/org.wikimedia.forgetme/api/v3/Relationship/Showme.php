<?php
use CRM_Forgetme_ExtensionUtil as E;
require_once 'api/v3/ShowmeUtils.php';

/**
 * email.Showme API specification (optional)
 * This is used for documentation and validation.
 *
 * @param array $spec description of fields supported by this API call
 * @return void
 * @see http://wiki.civicrm.org/confluence/display/CRMDOC/API+Architecture+Standards
 */
function _civicrm_api3_relationship_showme_spec(&$spec) {
}

/**
 * relationship.Showme API
 *
 * The point of this api is to get all data about a relationship with some prefiltering
 * and formatting.
 *
 * @param array $params
 * @return array API result descriptor
 * @see civicrm_api3_create_success
 * @see civicrm_api3_create_error
 * @throws CRM_Core_Exception
 */
function civicrm_api3_relationship_showme($params) {
  if (!empty($params['contact_id'])) {
    $params['contact_id_a'] = $params['contact_id'];
    $params['contact_id_b'] = $params['contact_id'];
    unset($params['contact_id']);
    $params['options']['or'] = [["contact_id_a", "contact_id_b"]];
  }
  return _civicrm_api3_generic_showme(['entity' => 'relationship', 'params' => $params]);
}


<?php
use CRM_Forgetme_ExtensionUtil as E;

/**
 * Contact.Showme API specification (optional)
 * This is used for documentation and validation.
 *
 * @param array $spec description of fields supported by this API call
 * @return void
 * @see http://wiki.civicrm.org/confluence/display/CRMDOC/API+Architecture+Standards
 */
function _civicrm_api3_contact_showme_spec(&$spec) {
  $spec['id']['api.required'] = 1;
  $spec['id']['type'] = CRM_Utils_Type::T_INT;
}

/**
 * Contact.Showme API
 *
 * The point of this api is to get all data about a contact with some prefiltering
 * and formatting.
 *
 * @param array $params
 * @return array API result descriptor
 * @see civicrm_api3_create_success
 * @see civicrm_api3_create_error
 * @throws CRM_Core_Exception
 */
function civicrm_api3_contact_showme($params) {
  $showMe = new CRM_Forgetme_Showme('Contact', $params, []);
  $metadata = $showMe->getMetadata();

  $contact = $showMe->getDisplayValues();
  $contact = reset($contact);

  $references = civicrm_api3('Contact', 'getrefcount', ['id' => $params['id'], 'debug=1']);
  $additionalObjects = [];
  foreach ($references['values'] as $reference) {
    $entity = CRM_Core_DAO_AllCoreTables::getEntityNameForClass(CRM_Core_DAO_AllCoreTables::getClassForTable($reference['table']));
    // Don't delete related contacts and avoid potential circular references
    if ($entity !== 'Contact') {
      $additionalObjects[$entity] = $reference['count'];
    }
  }

  foreach ($additionalObjects as $entity => $count) {
    $actions = civicrm_api3($entity, 'getactions', [])['values'];
    if (in_array('showme', $actions)) {
      $relatedEntities = civicrm_api3($entity, 'get', ['return' => 'id', 'contact_id' => $params['id']])['values'];
      foreach ($relatedEntities as $relatedEntity) {
        $contact[$entity . $relatedEntity['id']] = civicrm_api3($entity, 'showme', ['id' => $relatedEntity['id']])['showme'];
        $metadata[$entity . $relatedEntity['id']] = ['title' => ts($entity)];
      }
    }
    else {
      $contact[$entity] = ts('%count %2 record exists', [
        'count' => $count,
        1 => $entity,
        'plural' => '%count %2 records exist'
      ]);
      $metadata[$entity] = ['title' => ts($entity)];
    }
  }

  // An extension is basically allowed to register a showme function instead of declaring
  // itself as an entity & it will be called upon.
  $moreEntities = _civicrm_api3_contact_showme_get_entities_with_showme($contact, $metadata);
  foreach ($moreEntities as $entity) {
    $detail = civicrm_api3($entity, 'showme', ['contact_id' => $params['id']])['showme'];
    foreach ($detail as $id => $row) {
      $contact[$entity . $id] = $row;
      $metadata[$entity . $id] = ['title' => ts($entity)];
    }

  }

  $return = civicrm_api3_create_success([$contact['id'] => $contact], $params);
  $return['metadata'] = $metadata;
  return $return;
}

function _civicrm_api3_contact_showme_get_entities_with_showme($contact, $metadata) {
  $apiEntities = array_flip(civicrm_api3('Entity', 'get', [])['values']);
  $daoEntities = CRM_Core_DAO_AllCoreTables::getEntities();
  foreach ($daoEntities as $daoEntity) {
    // Convert first to fix up ones like 'Im'
    $name = CRM_Utils_String::convertStringToCamel($daoEntity['name']);
    if (isset($apiEntities[$name])) {
      unset($apiEntities[$name]);
    }
  }
  $apiEntities = _civicrm_api3_showme_get_entities_with_action('showme', array_keys($apiEntities));
  return $apiEntities;
}


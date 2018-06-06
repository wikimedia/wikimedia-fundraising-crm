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
 * @throws API_Exception
 */
function civicrm_api3_contact_showme($params) {
  $showMe = new CRM_Forgetme_Showme('Contact', $params);
  $showMe->setNegativeFields([
    'do_not_email',
    'do_not_trade',
    'do_not_phone',
    'do_not_email',
    'do_not_mail',
    'do_not_sms',
    'is_opt_out',
    'is_deceased',
    'is_deleted',
    'contact_is_deleted',
    'on_hold',
  ]);

  $internalFields = ['hash', 'api_key', 'sort_name', 'created_date', 'modified_date'];
  // Phone has a showme so we can hide here.
  $phoneFields = ['phone_id', 'phone', 'phone_type_id'];
  $showMe->setInternalFields(array_merge($internalFields, $phoneFields));
  $metadata = $showMe->getMetadata();

  $contact = $showMe->getDisplayValues();
  $contact = reset($contact);

  $references = civicrm_api3('Contact', 'getrefcount', ['id' => $params['id'], 'debug=1']);
  $additionalObjects = [];
  foreach ($references['values'] as $reference) {
    $entity = CRM_Core_DAO_AllCoreTables::getBriefName(CRM_Core_DAO_AllCoreTables::getClassForTable($reference['table']));
    $additionalObjects[$entity] = $reference['count'];
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

  $return = civicrm_api3_create_success([$contact['id'] => $contact], $params);
  $return['metadata'] = $metadata;
  return $return;
}


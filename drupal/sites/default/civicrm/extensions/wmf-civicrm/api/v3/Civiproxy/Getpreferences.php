<?php

use Civi\Api4\Contact;
use CRM_Wmf_ExtensionUtil as E;

/**
 * Civiproxy.getpreferences API specification (optional)
 * This is used for documentation and validation.
 *
 * @param array $spec description of fields supported by this API call
 *
 * @see https://docs.civicrm.org/dev/en/latest/framework/api-architecture/
 */
function _civicrm_api3_civiproxy_getpreferences_spec(&$spec) {
  $spec['checksum'] = [
    'name' => 'checksum',
    'title' => 'Checksum',
    'api.required' => TRUE,
    'type' => CRM_Utils_Type::T_STRING,
  ];
  $spec['contact_id'] = [
    'name' => 'contact_id',
    'title' => 'Contact ID',
    'api.required' => TRUE,
    'type' => CRM_Utils_Type::T_INT,
  ];
}

/**
 * Civiproxy.Getpreferences API
 *
 * @param array $params
 *
 * @return array
 *   API result descriptor
 *
 * @throws \CRM_Core_Exception
 * @see civicrm_api3_create_success
 */
function civicrm_api3_civiproxy_getpreferences(array $params): array {
  // Can check the checksum before doing our own select
  if (!CRM_Contact_BAO_Contact_Utils::validChecksum($params['contact_id'], $params['checksum'])) {
    throw new CRM_Core_Exception(E::ts('No result found'));
  }

  $result = (array) Contact::get(FALSE)
    ->addWhere('id', '=', (int) $params['contact_id'])
    ->setSelect([
      'preferred_language',
      'first_name',
      'address.country_id:name',
      'email.email',
      'is_opt_out',
      'Communication.opt_in',
      'email_primary.email_settings.snooze_date',
    ])
    ->addJoin('Address AS address', 'LEFT', ['address.is_primary', '=', 1])
    ->addJoin('Email AS email', 'LEFT', ['email.is_primary', '=', 1])
    ->execute()->first();

  if (empty($result)) {
    throw new CRM_Core_Exception(E::ts('No result found'));
  }

  // FIXME: use civicrm_api3_create_success
  return [
    'country' => $result['address.country_id:name'] ?? NULL,
    'email' => $result['email.email'] ?? NULL,
    'first_name' => $result['first_name'] ?? NULL,
    'preferred_language' => $result['preferred_language'] ?? NULL,
    'is_opt_in' => empty($result['is_opt_out']) && ($result['Communication.opt_in'] ?? NULL) !== FALSE,
    'snooze_date' => $result['email_primary.email_settings.snooze_date']
  ];

}

<?php

use Civi\Api4\Contact;
use Civi\Api4\CustomField as CustomField;
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
  $spec['hash'] = [
    'name' => 'hash',
    'title' => 'Hash',
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
 * Civiproxy.Preferences API
 *
 * @param array $params
 *
 * @return array
 *   API result descriptor
 *
 * @throws \API_Exception
 * @see civicrm_api3_create_success
 */
function civicrm_api3_civiproxy_getpreferences(array $params): array {
   $returnParams = [
    'preferred_language' => ['type' => 'string'],
    'first_name' => ['type' => 'string'],
    'address.country_id:name' => ['type' => 'string'],
    'email.email' => ['type' => 'string'],
    'is_opt_out' => ['type' => 'bool'],
    'Communication.opt_in' => ['type' => 'bool', 'field_name' => 'is_opt_in']
  ];

   $result = (array) Contact::get(FALSE)
     ->addWhere('hash', '=', (string) $params['hash'])
     ->addWhere('id', '=', (int) $params['contact_id'])
     ->setSelect(array_keys($returnParams))
     ->addJoin('Address AS address', 'LEFT', ['address.is_primary', '=', 1])
     ->addJoin('Email AS email', 'LEFT', ['email.is_primary', '=', 1])
     ->execute()->first();

   if (empty($result)) {
     throw new API_Exception(E::ts('No result found'));
   }

   return [
     'country' => $result['address.country_id:name']?? NULL,
     'email' => $result['email.email']?? NULL,
     'first_name' => $result['first_name'] ?? NULL,
     'preferred_language' => $result['preferred_language'] ?? NULL,
     'is_opt_in' => empty($result['is_opt_out']) && ($result['Communication.opt_in'] ?? NULL) !== FALSE
   ];

}

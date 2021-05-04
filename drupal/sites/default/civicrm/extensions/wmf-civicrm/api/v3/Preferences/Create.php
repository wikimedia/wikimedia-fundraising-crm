<?php

use Civi\Api4\Contact;

/**
 * PreferencesQueue.consume API specification (optional)
 * This is used for documentation and validation.
 *
 * @param array $spec description of fields supported by this API call
 *
 * @see https://docs.civicrm.org/dev/en/latest/framework/api-architecture/
 */
function _civicrm_api3_preferences_create_spec(&$spec) {
  $spec['contact_hash'] = [
    'name' => 'contact_hash',
    'title' => 'Contact Hash',
    'api.required' => TRUE,
    'type' => CRM_Utils_Type::T_STRING,
  ];
  $spec['contact_id'] = [
    'name' => 'contact_id',
    'title' => 'Contact ID',
    'api.required' => TRUE,
    'type' => CRM_Utils_Type::T_INT,
  ];
   $spec['language'] = [
     'name' => 'language',
     'title' => 'Language',
     'api.required' => TRUE,
     'type' => CRM_Utils_Type::T_STRING,
   ];
    $spec['send_email'] = [
      'name' => 'send_email',
      'title' => 'Send Email',
      'api.required' => TRUE,
      'type' => CRM_Utils_Type::T_BOOLEAN,
    ];
}

/**
 * PreferencesQueue.consume API
 *
 * @param array $params
 *
 * @return array
 *   API result descriptor
 *
 * @throws \API_Exception
 * @see civicrm_api3_create_success
 */
function civicrm_api3_preferences_create(array $params): array {

  if (
    !preg_match('/^[0-9a-f]*$/', $params['contact_hash']) ||
    !preg_match('/^[0-9a-zA-Z_-]*$/', $params['language'])
  ) {
    throw new API_Exception('Invalid data in e-mail preferences message.', 'invalid_message');
  }

  $result = Contact::update(FALSE)->setValues([
    'Communication.opt_in' => $params['send_email'],
    'preferred_language' => $params['language'],
  ])
    ->addWhere('hash', '=', (string) $params['contact_hash'])
    ->addWhere('id', '=', (int) $params['contact_id'])
    ->execute()->first();
  return civicrm_api3_create_success((array([$params['contact_id'] => (array) $result])), $params, 'Preferencesqueue', 'consume');
}

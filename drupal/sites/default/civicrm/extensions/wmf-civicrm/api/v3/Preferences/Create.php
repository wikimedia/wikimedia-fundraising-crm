<?php

use Civi\Api4\Contact;
use Civi\Api4\Address;
use Civi\Api4\Country;

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
  $spec['country'] = [
    'name' => 'country',
    'title' => 'Country',
    'api.required' => TRUE,
    'type' => CRM_Utils_Type::T_STRING,
  ];
  $spec['send_email'] = [
    'name' => 'send_email',
    'title' => 'Send Email',
    'api.required' => TRUE,
    'type' => CRM_Utils_Type::T_STRING,
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
    !preg_match('/^[0-9a-zA-Z_-]*$/', $params['language']) ||
    !preg_match('/^[a-zA-Z]*$/', $params['country'])
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

  $address = Address::get(FALSE)
    ->addSelect('country_id.iso_code')
    ->addWhere('contact_id', '=', (int) $params['contact_id'])
    ->addWhere('location_type_id:name', '=', 'EmailPreference')
    ->execute();

  $countryId = Country::get(FALSE)
    ->addWhere('iso_code', '=', (string) $params['country'])
    ->execute()
    ->first()['id'];

  Civi::log('wmf')
    ->info("Email Preference Center update - civicrm_contact id: {$params['contact_id']}'s preferred_language to {$params['language']}, country to {$params['country']}($countryId) and civicrm_value_1_communication_4.opt_in to {$params['send_email']}.");
  // country_id could be null, because we get the country options from CountryNames::getNames( $uiLang ),
  // and here we update country_id in civicrm_address which has FK with civicrm_country.
  // Need to confirm what should we do with the null country_id?
  if(!$countryId){
    Civi::log('wmf')
      ->error("Email Preference Center update - civicrm_contact id: {$params['contact_id']}'s is trying to update preferred_language to {$params['language']}, country to {$params['country']}, and civicrm_value_1_communication_4.opt_in to {$params['send_email']}, but no country_id is been found from civicrm_country table.");
  }

  if (count($address) === 1) {
    Address::update(FALSE)->setValues([
      'country_id.iso_code' => (string) $params['country'],
      'location_type_id:name' => 'EmailPreference',
      'is_primary' => 1,
    ])
      ->addWhere('location_type_id:name', '=', 'EmailPreference')
      ->addWhere('contact_id', '=', (int) $params['contact_id'])
      ->execute();
  }
  else {
    // Our UI currently use is_primary = 1's country, so do we update the EmailPreference is_primary to 1 and others to 0
    // Or we do not update the is_primary just check if emailPreference exist for UI?
    Address::create(FALSE)->setValues([
      'location_type_id:name' => 'EmailPreference',
      'country_id.iso_code' => (string) $params['country'],
      'contact_id' => (int) $params['contact_id'],
      'is_primary' => 1,
    ])->execute();
  }

  return civicrm_api3_create_success(([[$params['contact_id'] => (array) $result]]), $params, 'Preferencesqueue', 'consume');
}

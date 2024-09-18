<?php

use Civi\Api4\Email;
use Civi\Api4\Contact;
use Civi\Api4\Address;

/**
 * Preferences.create API specification (optional)
 * This is used for documentation and validation.
 *
 * @param array $spec description of fields supported by this API call
 *
 * @see https://docs.civicrm.org/dev/en/latest/framework/api-architecture/
 */
function _civicrm_api3_preferences_create_spec(&$spec) {
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
  $spec['language'] = [
    'name' => 'language',
    'title' => 'Language',
    'api.required' => TRUE,
    'type' => CRM_Utils_Type::T_STRING,
  ];
  $spec['email'] = [
    'name' => 'email',
    'title' => 'Email',
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
    'api.required' => FALSE,
    'type' => CRM_Utils_Type::T_STRING,
  ];
  $spec['snooze_date'] = [
    'name' => 'snooze_date',
    'title' => 'Snooze Date',
    'api.required' => FALSE,
    'type' => CRM_Utils_Type::T_STRING,
  ];
}

/**
 * Preferences.create API
 *
 * @param array $params
 *
 * @return array
 *   API result descriptor
 *
 * @throws \CRM_Core_Exception
 * @see civicrm_api3_create_success
 */
function civicrm_api3_preferences_create(array $params): array {
  if (
    !preg_match('/^[0-9a-f_]*$/', $params['checksum']) ||
    !preg_match('/^[0-9a-zA-Z_-]*$/', $params['language']) ||
    !preg_match('/^[a-zA-Z]*$/', $params['country']) ||
    (
      !empty($params['snooze_date']) &&
      !preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/', $params['snooze_date'])
    )
  ) {
    throw new CRM_Core_Exception('Invalid data in e-mail preferences message.', 'invalid_message');
  }

  if (!CRM_Contact_BAO_Contact_Utils::validChecksum($params['contact_id'], $params['checksum'])) {
    throw new CRM_Core_Exception('Checksum mismatch');
  }
  $contactID = (int) $params['contact_id'];
  $contactExists = !empty(Contact::get(FALSE)
    ->addSelect('id')
    ->addWhere('id', '=', $contactID)
    ->addWhere('is_deleted', '=', FALSE)
    ->setLimit(1)
    ->execute()
    ->first());

  if (!$contactExists) {
    // Try to get any merged contact ID
    $contactID = (int) key(
      civicrm_api3('Contact', 'getmergedto', ['contact_id' => $contactID])['values']
    );
    if (!$contactID) {
      throw new CRM_Core_Exception("No contact found with ID {$params['contact_id']}, even after getmergedto");
    }
    Civi::log('wmf')->info(
      "Contact with ID {$params['contact_id']} from preferences message has been merged into contact with ID $contactID"
    );
  }

  $contactUpdateValues = ['preferred_language' => $params['language']];
  if (array_key_exists('send_email', $params)) {
    $contactUpdateValues['Communication.opt_in'] = $params['send_email'];
  }
  $result = Contact::update(FALSE)->setValues($contactUpdateValues)
    ->addWhere('id', '=', $contactID)
    ->execute()->first();

  $address = Address::get(FALSE)
    ->addSelect('country_id.iso_code')
    ->addWhere('contact_id', '=', $contactID)
    ->addWhere('location_type_id:name', '=', 'EmailPreference')
    ->execute();

  $email = Email::get(FALSE)
    ->addWhere('contact_id', '=', $contactID)
    ->addWhere('is_primary', '=',1)
    ->execute();

  Civi::log('wmf')
    ->info("Email Preference Center update - civicrm_contact id: $contactID's preferred_language to {$params['language']}, country to {$params['country']} and civicrm_value_1_communication_4.opt_in to {$params['send_email']}.");

  if (count($address) === 1) {
    Address::update(FALSE)->setValues([
      'country_id.iso_code' => (string) $params['country'],
      'is_primary' => 1,
    ])
      ->addWhere('id', '=', $address->first()['id'])
      ->execute();
  }
  else {
    // Our UI currently use is_primary = 1's country, so do we update the EmailPreference is_primary to 1 and others to 0
    // Or we do not update the is_primary just check if emailPreference exist for UI?
    Address::create(FALSE)->setValues([
      'location_type_id:name' => 'EmailPreference',
      'country_id.iso_code' => (string) $params['country'],
      'contact_id' => $contactID,
      'is_primary' => 1,
    ])->execute();
  }

  // We just need to set this value here. The omnimail_civicrm_custom hook will pick up
  // the change and queue up an API request to Acoustic to actually snooze it.
  $snoozeValues = empty($params['snooze_date']) ? [] : ['email_settings.snooze_date' => $params['snooze_date']];

  if (count($email) === 1) {
    Email::update(FALSE)->setValues([
      'email' => (string) $params['email']
    ] + $snoozeValues)
      ->addWhere('id', '=', $email->first()['id'])
      ->execute();
  }
  else {
    Email::create(FALSE)->setValues([
      'email' => (string) $params['email'],
      'contact_id' => $contactID,
      'is_primary' => 1,
    ] + $snoozeValues)->execute();
  }

  // TODO: add info about email and address updates to the contact update result
  return civicrm_api3_create_success([$contactID => (array) $result], $params, 'Preferences', 'create');
}

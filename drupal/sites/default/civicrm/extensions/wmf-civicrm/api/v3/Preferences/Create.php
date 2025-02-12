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
    'api.required' => FALSE,
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
    'api.required' => FALSE,
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
  // validate passing params
  if (
    (
      !empty($params['checksum']) &&
      !preg_match('/^[0-9a-f_]*$/', $params['checksum'])
    ) || (
      !empty($params['email']) &&
      !filter_var($params['email'], FILTER_VALIDATE_EMAIL)
    ) || (
      !empty($params['language']) &&
      !preg_match('/^[0-9a-zA-Z_-]*$/', $params['language'])
    ) || (
      !empty($params['country']) &&
      !preg_match('/^[a-zA-Z]*$/', $params['country'])
    ) || (
      !empty($params['snooze_date']) &&
      !preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/', $params['snooze_date'])
    )
  ) {
    throw new CRM_Core_Exception('Invalid data in e-mail preferences message.', 'invalid_message');
  }

  // validate checksum (for queue with fallback unsubscribe still need these two value to validate)
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
  $result = [];
  $contactUpdateValues = [];
  $message = "Email Preference Center update - civicrm_contact id: $contactID";
  if (array_key_exists('send_email', $params)) {
    $contactUpdateValues['Communication.opt_in'] = $params['send_email'];
    $message .= ", opt_in to {$params['send_email']}";
  }

  if (!empty($params['language'])) {
    $contactUpdateValues['preferred_language'] = $params['language'];
    $message .= ", preferred_language to {$params['language']}";
  }
  if ($contactUpdateValues !== []) {
    $contactResult = Contact::update(FALSE)->setValues($contactUpdateValues)
      ->addWhere('id', '=', $contactID)
      ->execute()->first();
    $result = array_merge($result,$contactResult);
  }

  if (!empty($params['country'])) {
    $address = Address::get(FALSE)
      ->addSelect('country_id.iso_code')
      ->addWhere('contact_id', '=', $contactID)
      ->addWhere('location_type_id:name', '=', 'EmailPreference')
      ->execute();

    if (count($address) === 1) {
      $addressResult = Address::update(FALSE)->setValues([
        'country_id.iso_code' => (string) $params['country'],
        'is_primary' => 1,
      ])
        ->addWhere('id', '=', $address->first()['id'])
        ->execute()->first();
    } else {
      // Our UI currently use is_primary = 1's country, so do we update the EmailPreference is_primary to 1 and others to 0
      // Or we do not update the is_primary just check if emailPreference exist for UI?
      $addressResult = Address::create(FALSE)->setValues([
        'location_type_id:name' => 'EmailPreference',
        'country_id.iso_code' => (string) $params['country'],
        'contact_id' => $contactID,
        'is_primary' => 1,
      ])->execute()->first();
    }
    $result = array_merge($result, $addressResult);
    $message .= ", country to {$params['country']}";
  }

  // We just need to set this value here. The omnimail_civicrm_custom hook will pick up
  // the change and queue up an API request to Acoustic to actually snooze it.
  $email = Email::get(FALSE)
    ->addWhere('contact_id', '=', $contactID)
    ->addWhere('is_primary', '=',1)
    ->execute();
  $isEmailUpdated = (count($email) === 1 ? $email->first()['email'] : NULL) !== $params['email'];
  if ($isEmailUpdated) {
    $message .= ", email to {$params['email']}";
  }
  $snoozeValues = [];
  if (!empty($params['snooze_date'])) {
    $snoozeValues = ['email_settings.snooze_date' => $params['snooze_date']];
    $message .= ", snooze date to {$params['snooze_date']}";
  }

  if ($isEmailUpdated || $snoozeValues !== []) {
    if (count($email) === 1) {
      $emailResult = Email::update(FALSE)->setValues([
          'email' => (string) $params['email']
        ] + $snoozeValues)
        ->addWhere('id', '=', $email->first()['id'])
        ->execute()->first();
    } else {
      $emailResult = Email::create(FALSE)->setValues([
          'email' => (string) $params['email'],
          'contact_id' => $contactID,
          'is_primary' => 1,
        ] + $snoozeValues)->execute()->first();
    }
    $result = array_merge($result, $emailResult);
  }

  Civi::log('wmf')->info($message. '.');

  return civicrm_api3_create_success([$contactID => $result], $params, 'Preferences', 'create');
}

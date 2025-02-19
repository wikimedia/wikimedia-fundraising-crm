<?php

use Civi\Api4\Email;
use Civi\Api4\Contact;
use Civi\Api4\Address;
use Civi\Api4\Activity;

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
  validateInput($params);
  $contactID = (int) $params['contact_id'];
  $contact = getEmailPreferenceData($contactID);
  if (empty($contact)) {
    // Try to get any merged contact ID
    $contactID = (int) key(
      civicrm_api3('Contact', 'getmergedto', ['contact_id' => $contactID])['values']
    );
    if (!$contactID) {
      throw new CRM_Core_Exception("No contact found with ID {$params['contact_id']}, even after getmergedto");
    }
    $contact = getEmailPreferenceData($contactID);
    Civi::log('wmf')->info(
      "Contact with ID {$params['contact_id']} from preferences message has been merged into contact with ID $contactID"
    );
  }

  $message = "Email Preference Center update - civicrm_contact id: $contactID";
  $result = [];
  $snoozeValues = [];
  $contactUpdateValues = [];
  $oldOptInValue = $contact['Communication.opt_in'];
  $oldSnoozeDateValue = $contact['email_primary.email_settings.snooze_date'];
  $oldLanguageValue = $contact['preferred_language'];
  $oldEmailValue = $contact['email.email'];
  $oldCountryValue = $contact['address.country_id:name'];
  // 1: send email update
  if (array_key_exists('send_email', $params)) {
    $newOptIn = $params['send_email'] === 'true' ? 1 : 0;
    // if update send email, or reset snooze date
    if ($newOptIn != $oldOptInValue || (
      $oldSnoozeDateValue !== null && $oldSnoozeDateValue !== date("Y-m-d", strtotime("+1 day"))
      )) {
      // Log the send_email activity for GDPR
      $contactUpdateValues['Communication.opt_in'] = $params['send_email'];
      // need to set snooze_date to tomorrow, if it has set and later than tomorrow
      // still need to check snoozeValue here in case unsubscribe request send from fallback
      if ($oldSnoozeDateValue !== null &&
        strtotime($oldSnoozeDateValue) > strtotime('+1 day')) {
        $snoozeValues['email_settings.snooze_date'] = date("Y-m-d", strtotime("+1 day"));
        $message .= ', since current snoozeValue was '. $oldSnoozeDateValue . ', so set snoozeValue to ' . $snoozeValues['email_settings.snooze_date'];
      }
      $message .= ", opt_in from {$oldOptInValue} to {$params['send_email']}";
      $detail = "Email Preference Center update opt_in from {$oldOptInValue} to {$params['send_email']}";
      if ($params['send_email'] === 'true') {
        logActivity("OptIn", $detail, $params['contact_id']);
      } else {
        logActivity("unsubscribe", $detail, $params['contact_id']);
      }
    }
  }

  // 2: language update
  if (!empty($params['language']) && $oldLanguageValue !== $params['language']) {
    $contactUpdateValues['preferred_language'] = $params['language'];
    $message .= ", preferred_language from {$oldLanguageValue} to {$params['language']}";
  }

  if ($contactUpdateValues !== []) {
    $contactResult = Contact::update(FALSE)->setValues($contactUpdateValues)
      ->addWhere('id', '=', $contactID)
      ->execute()->first();
    $result = array_merge($result, $contactResult);
  }

  // 3: country update
  if (!empty($params['country']) && $oldCountryValue !== $params['country']) {
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
    $message .= ", country from {$oldCountryValue} to {$params['country']}";
  }

  // We just need to set this value here. The omnimail_civicrm_custom hook will pick up
  // the change and queue up an API request to Acoustic to actually snooze it.
  // 4: update snoozed_date
  if (!empty($params['snooze_date']) && $params['snooze_date']!== $oldSnoozeDateValue) {
    $snoozeValues = ['email_settings.snooze_date' => $params['snooze_date']];
    $message .= ", snooze date from {$oldSnoozeDateValue} to {$params['snooze_date']}";
  }

  // 5: update email
  $email = Email::get(FALSE)
    ->addWhere('contact_id', '=', $contactID)
    ->addWhere('is_primary', '=',1)
    ->execute();
  $isEmailUpdated = $oldEmailValue !== $params['email'];
  if ($isEmailUpdated) {
    $message .= ", email from {$oldEmailValue} to {$params['email']}";
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

  // only log when epc have the info update
  if ($result !== []) {
      logActivity('Email Preference Center', "$message.", $params['contact_id']);
  }
  Civi::log('wmf')->info($message. '.');
  return civicrm_api3_create_success([$contactID => $result], $params, 'Preferences', 'create');
}

function validateInput($params) {
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
}

function getEmailPreferenceData($contactID) {
  return Contact::get(FALSE)
    ->addWhere('id', '=', $contactID)
    ->addWhere('is_deleted', '=', FALSE)
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
}

function logActivity($activityName, $detail, $contactId) {
  $subject = $activityName === 'Email Preference Center' ? 'Preferences Updated' : "$activityName - Email Preference Center";
  Activity::create(FALSE)
    ->addValue('activity_type_id:name', $activityName)
    ->addValue('status_id:name', 'Completed')
    ->addValue('subject', $subject)
    ->addValue('details', $detail)
    ->addValue('source_contact_id', $contactId)
    ->addValue('source_record_id', $contactId)
    ->addValue('activity_date_time', 'now')
    ->execute();
}


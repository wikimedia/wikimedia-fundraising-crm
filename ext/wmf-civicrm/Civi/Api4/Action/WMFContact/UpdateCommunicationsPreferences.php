<?php

namespace Civi\Api4\Action\WMFContact;

use Civi\API\Exception\UnauthorizedException;
use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\Generic\Result;
use Civi\Api4\Email;
use Civi\Api4\Contact;
use Civi\Api4\Address;
use Civi\Api4\Activity;

/**
 * Class UpdateCommunicationsPreferences.
 *
 * Update contact info from queue consume
 *
 * @method $this setEmail(string $email)
 * @method string getEmail() Get email from email preference center queue.
 * @method $this setContactID(int $contactID)
 * @method int getContactID() Get contactID from email preference center queue.
 * @method $this setChecksum(string $checksum)
 * @method string getChecksum() Get checksum from email preference center queue.
 * @method $this setCountry(?string $country)
 * @method string|null getCountry() Get country from email preference center queue.
 * @method $this setSnoozeDate(?string $snoozeDate)
 * @method string|null getSnoozeDate() Get snoozeDate from email preference center queue.
 * @method $this setSendEmail(?string $sendEmail)
 * @method string|null getSendEmail() Get sendEmail from email preference center queue.
 *
 * */
class UpdateCommunicationsPreferences extends AbstractAction {

  /**
   * contact_id from email preference center queue.
   * @required
   * @var int
   */
  protected $contactID;

  /**
   * checksum from email preference center queue.
   * @required
   * @var string
   */
  protected $checksum;

  /**
   * email from email preference center queue.
   * @required
   * @var string
   */
  protected $email;

  /**
   * country from email preference center queue.
   * @var string|null
   * @optionsCallback getPreferCountryOptions
   */
  protected $country = null;

  /**
   * snoozeDate from email preference center queue.
   * @var string|null
   */
  protected $snoozeDate = null;

  /**
   * sendEmail from email preference center queue as opt in.
   * @var string|null
   * @optionsCallback getSendEmailOptions
   */
  protected $sendEmail = null;

  /**
   * @throws \CRM_Core_Exception
   * @throws UnauthorizedException
   */
  public function _run(Result $result): void {
    $params = [
      'contact_id' => $this->contactID,
      'checksum' => $this->checksum,
      'email' => $this->email,
    ];
    if ($this->country) {
      $params['country'] = $this->country;
    }
    if ($this->snoozeDate) {
      $params['snooze_date'] = $this->snoozeDate;
    }
    if ($this->sendEmail) {
      $params['send_email'] = $this->sendEmail;
    }
    if ($this->language) {
      $params['language'] = $this->language;
    }
    // validate params
    $this->validateInput($params);
    $contactID = (int) $params['contact_id'];
    $contact = $this->getEmailPreferenceData($contactID);

    if (empty($contact)) {
      // Try to get any merged contact ID
      $contactID = (int) Contact::getMergedTo(FALSE)
        ->setContactId($contactID)
        ->execute()
        ->first()['id'];
      if (!$contactID) {
        throw new \CRM_Core_Exception("No contact found with ID {$params['contact_id']}, even after getmergedto");
      }
      $contact = $this->getEmailPreferenceData($contactID);
      \Civi::log('wmf')->info(
        "Contact with ID {$params['contact_id']} from preferences message has been merged into contact with ID $contactID"
      );
    }

    $message = "Email Preference Center update - civicrm_contact id: $contactID";
    $outcome = [];
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
          $message .= ', since current snoozeValue was ' . $oldSnoozeDateValue . ', so set snoozeValue to ' . $snoozeValues['email_settings.snooze_date'];
        }
        $message .= ", opt_in from {$oldOptInValue} to {$params['send_email']}";
        $detail = "Email Preference Center update opt_in from {$oldOptInValue} to {$params['send_email']}";
        if ($params['send_email'] === 'true') {
          $this->logActivity("OptIn", $detail, $params['contact_id']);
        } else {
          $this->logActivity("unsubscribe", $detail, $params['contact_id']);
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
      $outcome = array_merge($outcome, $contactResult);
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
      $outcome = array_merge($outcome, $addressResult);
      $message .= ", country from {$oldCountryValue} to {$params['country']}";
    }

    // We just need to set this value here. The omnimail_civicrm_custom hook will pick up
    // the change and queue up an API request to Acoustic to actually snooze it.
    // 4: update snoozed_date
    if (!empty($params['snooze_date']) && $params['snooze_date'] !== $oldSnoozeDateValue) {
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
      }
      else {
        $emailResult = Email::create(FALSE)->setValues([
            'email' => (string) $params['email'],
            'contact_id' => $contactID,
            'is_primary' => 1,
          ] + $snoozeValues)->execute()->first();
      }

      $outcome = array_merge($outcome, $emailResult);
    }

    // only log when epc have the info update
    if ($outcome !== []) {
      $this->logActivity('Email Preference Center', "$message.", $params['contact_id']);
    }
    \Civi::log('wmf')->info($message. '.');

    $result[] = $outcome;
  }

  /**
   * Validate input parameters
   * @param array $params
   * @throws \CRM_Core_Exception
   */
  function validateInput($params): void {
    // check if required params exist
    if (empty($params['email']) ||
      empty($params['contact_id']) ||
      empty($params['checksum'])
    ) {
      throw new \CRM_Core_Exception('Missing required parameters in e-mail preferences message.');
    }
    // check if params are valid
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
      throw new \CRM_Core_Exception('Invalid data in e-mail preferences message.');
    }
    // validate checksum, but allow expired checksum
    if (!$this->validateChecksum($params['contact_id'], $params['checksum'])) {
      throw new \CRM_Core_Exception('Checksum mismatch.');
    }
  }

  /**
   * Similar to \CRM_Contact_BAO_Contact_Utils::validateChecksum just without the timestamp check part
   * This is used to validate the checksum from the email preference center queue
   *
   * @param $contactID
   * @param $inputCheck
   * @return bool
   * @throws \CRM_Core_Exception
   */
  function validateChecksum($contactID, $inputCheck): bool {
    // This is a helper function to validate the checksum
    // Allow a hook to invalidate checksums
    $invalid = FALSE;
    \CRM_Utils_Hook::invalidateChecksum($contactID, $inputCheck, $invalid);
    if ($invalid) {
      return FALSE;
    }
    $input = \CRM_Utils_System::explode('_', $inputCheck, 3);
    $inputTS = $input[1] ?? NULL;
    $inputLF = $input[2] ?? NULL;

    $check = \CRM_Contact_BAO_Contact_Utils::generateChecksum($contactID, $inputTS, $inputLF);
    if (!hash_equals($check, (string) $inputCheck)) {
      return FALSE;
    }
    // If we get here, the checksum is valid
    return TRUE;
  }

  /**
   * @throws UnauthorizedException
   * @throws \CRM_Core_Exception
   */
  function getEmailPreferenceData($contactID): ?array {
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

  /**
   * @throws \CRM_Core_Exception
   * @throws UnauthorizedException
   */
  function logActivity($activityName, $detail, $contactId): void {
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

  protected function getLangs(): array {
    $languages = \CRM_Contact_BAO_Contact::buildOptions('preferred_language');
    asort($languages);
    $result = array_keys($languages);
    if (!\Civi::settings()->get('partial_locales')) {
      $uiLanguages = \CRM_Core_I18n::uiLanguages(TRUE);
      $result = array_values(array_intersect($result, $uiLanguages));
    }
    return $result;
  }

  /**
   * Get available preferred country for epc.
   *
   * @return array
   */
  protected function getPreferCountryOptions(): array {
    $langs = $this->getLangs();
    $result = [];
    foreach ( $langs as $lang ) {
      // push the country code to the result array
      $code = explode('_', $lang)[1];
      $result[] = $code;
    }
    return $result;
  }

  /**
   * @return array
   */
  protected function getSendEmailOptions(): array {
    return [
      'true' => 'true',
      'false' => 'false',
    ];
  }
}

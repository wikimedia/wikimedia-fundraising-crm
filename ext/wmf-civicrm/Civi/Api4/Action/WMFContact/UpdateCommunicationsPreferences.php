<?php

namespace Civi\Api4\Action\WMFContact;

use Civi\API\Exception\UnauthorizedException;
use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\Generic\Result;
use Civi\Api4\Email;
use Civi\Api4\Contact;
use Civi\Api4\Address;
use Civi\Api4\Activity;
use Civi\Api4\WorkflowMessage;
use Civi\WorkflowMessage\SetPrimaryEmailMessage;

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
 * @method string getChecksum() Get emailChecksum from email preference center queue.
 * @method $this setEmailChecksum(?string $emailChecksum)
 * @method string getEmailChecksum() Get emailChecksum from email preference center queue.
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
   * checksum prepared for set primary email queue should exist if email updated.
   * @var string
   */
  protected $emailChecksum;

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
    // validate params
    $this->validateInput();
    $contact = $this->getEmailPreferenceData($this->contactID);

    if (empty($contact)) {
      // Try to get any merged contact ID
      $mergedContactID = (int) Contact::getMergedTo(FALSE)
        ->setContactId($this->contactID)
        ->execute()
        ->first()['id'];
      if (!$mergedContactID) {
        throw new \CRM_Core_Exception("No contact found with ID $this->contactID, even after getmergedto");
      }
      $contact = $this->getEmailPreferenceData($mergedContactID);
      \Civi::log('wmf')->info(
        "Contact with ID $this->contactID from preferences message has been merged into contact with ID $mergedContactID"
      );
      $this->contactID = $mergedContactID;
    }
    $message = "";
    $outcome = [];
    $snoozeValues = [];
    $contactUpdateValues = [];
    $oldOptInValue = $contact['Communication.opt_in'] ? 1 : 0;
    $oldSnoozeDateValue = $contact['email_primary.email_settings.snooze_date'];
    $oldLanguageValue = $contact['preferred_language'];
    $oldEmailValue = $contact['email.email'];
    $oldCountryValue = $contact['address.country_id:name'];
    // 1: send email update
    if ($this->sendEmail !== null) {
      $newOptIn = $this->sendEmail === 'true' ? 1 : 0;
      // if update send email, or reset snooze date
      if ($newOptIn != $oldOptInValue || (
          $oldSnoozeDateValue !== null && $oldSnoozeDateValue !== date("Y-m-d", strtotime("+1 day"))
        )) {
        // Log the send_email activity for GDPR
        $contactUpdateValues['Communication.opt_in'] = $this->sendEmail;
        // if No Bulk emails is set, we need to remove it
        // (this should be removable in the future when we fix the multiple optin/out fields problem)
        if ($this->sendEmail && $contact['is_opt_out'] === TRUE) {
          $contactUpdateValues['is_opt_out'] = FALSE;
        }
        // need to set snooze_date to tomorrow, if it has set and later than tomorrow
        // still need to check snoozeValue here in case unsubscribe request send from fallback
        if ($oldSnoozeDateValue !== null &&
          strtotime($oldSnoozeDateValue) > strtotime('+1 day')) {
          $snoozeValues['email_settings.snooze_date'] = date("Y-m-d", strtotime("+1 day"));
          $message .= ', since current snoozeValue was ' . $oldSnoozeDateValue . ', so set snoozeValue to ' . $snoozeValues['email_settings.snooze_date'];
        }
        $message .= ", opt_in from {$oldOptInValue} to {$newOptIn}";
        $detail = "Email Preference Center update opt_in from {$oldOptInValue} to {$newOptIn}";
        if ($this->sendEmail === 'true') {
          $this->logActivity("OptIn", $detail, $this->contactID);
        } else {
          $this->logActivity("unsubscribe", $detail, $this->contactID);
        }
      }
    }

    // 2: language update
    if (!empty($this->language) && $oldLanguageValue !== $this->language) {
      $contactUpdateValues['preferred_language'] = $this->language;
      $message .= ", preferred_language from {$oldLanguageValue} to $this->language";
    }

    if ($contactUpdateValues !== []) {
      $contactResult = Contact::update(FALSE)->setValues($contactUpdateValues)
        ->addWhere('id', '=', $this->contactID)
        ->execute()->first();
      $outcome = array_merge($outcome, $contactResult);
    }

    // 3: country update
    if (!empty($this->country) && $oldCountryValue !== $this->country) {
      $address = Address::get(FALSE)
        ->addSelect('country_id.iso_code')
        ->addWhere('contact_id', '=', $this->contactID)
        ->addWhere('location_type_id:name', '=', 'EmailPreference')
        ->execute();

      if (count($address) === 1) {
        $addressResult = Address::update(FALSE)->setValues([
          'country_id.iso_code' => (string) $this->country,
          'is_primary' => 1,
        ])
          ->addWhere('id', '=', $address->first()['id'])
          ->execute()->first();
      } else {
        // Our UI currently use is_primary = 1's country, so do we update the EmailPreference is_primary to 1 and others to 0
        // Or we do not update the is_primary just check if emailPreference exist for UI?
        $addressResult = Address::create(FALSE)->setValues([
          'location_type_id:name' => 'EmailPreference',
          'country_id.iso_code' => (string) $this->country,
          'contact_id' => $this->contactID,
          'is_primary' => 1,
        ])->execute()->first();
      }
      $outcome = array_merge($outcome, $addressResult);
      $message .= ", country from {$oldCountryValue} to $this->country";
    }

    // We just need to set this value here. The omnimail_civicrm_custom hook will pick up
    // the change and queue up an API request to Acoustic to actually snooze it.
    // 4: update snoozed_date
    if (!empty($this->snoozeDate) && $this->snoozeDate !== $oldSnoozeDateValue) {
      $snoozeValues = ['email_settings.snooze_date' => $this->snoozeDate];
      $message .= ", snooze date from {$oldSnoozeDateValue} to $this->snoozeDate";
    }

    // 5: update email - trigger verification email if email changed
    $email = Email::get(FALSE)
      ->addWhere('contact_id', '=', $this->contactID)
      ->addWhere('is_primary', '=',1)
      ->execute();
    $isEmailUpdated = $oldEmailValue !== $this->email;
    if ($isEmailUpdated) {
      // Send verification email to donor to confirm if confirm primary update
      $this->sendVerificationEmail(
        $contact
      );
      // send email change activity
      $this->logActivity("Send Verification Email",
        "Try to update EmailPreference email from $oldEmailValue to $this->email and send verification email.",
        $this->contactID);
    }

    // 6: if snooze date updated, update/create email record
    if ($snoozeValues !== []) {
      if (count($email) === 1) {
        $emailResult = Email::update(FALSE)->setValues($snoozeValues)
          ->addWhere('id', '=', $email->first()['id'])
          ->execute()->first();
      }
      else {
        $emailResult = [];
      }
      // log activity for snooze date update
      $this->logActivity("Email Preference Center",
        "Email Preference Center update snooze date from {$oldSnoozeDateValue}
          to {$snoozeValues['email_settings.snooze_date']}", $this->contactID);
      $outcome = array_merge($outcome, $emailResult);
    }

    // only log when epc have the info update
    if ($outcome !== []) {
      $message = "Email Preference Center update - civicrm_contact id: $this->contactID " . $message . '.';
      \Civi::log('wmf')->info($message);
      $this->logActivity('Email Preference Center', "$message.", $this->contactID);
    }

    $result[] = $outcome;
  }

  /**
   * Validate input parameters
   * @throws \CRM_Core_Exception
   */
  function validateInput(): void {
    // check if required params exist
    if (
      empty($this->email) ||
      empty($this->contactID) ||
      empty($this->checksum)
    ) {
      throw new \CRM_Core_Exception('Missing required parameters in e-mail preferences message.');
    }
    // check if params are valid
    if (
      (
        !preg_match('/^[0-9a-f_]*$/', $this->checksum)
      ) || (
        !filter_var($this->email, FILTER_VALIDATE_EMAIL)
      ) || (
        !empty($this->language) &&
        !preg_match('/^[0-9a-zA-Z_-]*$/', $this->language)
      ) || (
        !empty($this->country) &&
        !preg_match('/^[a-zA-Z]*$/', $this->country)
      ) || (
        !empty($this->snoozeDate) &&
        !preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/', $this->snoozeDate)
      )
    ) {
      throw new \CRM_Core_Exception('Invalid data in e-mail preferences message.');
    }
    // validate checksum, but allow expired checksum
    if (!$this->validateChecksum($this->contactID, $this->checksum)) {
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

  /**
   * @param array $contact
   * @return void
   * @throws UnauthorizedException
   * @throws \CRM_Core_Exception
   */
  function sendVerificationEmail(array $contact): void {
    // should have email_checksum passed in to send verification email
    if ( empty($this->emailChecksum) ) {
      throw new \CRM_Core_Exception('Missing required checksum in e-mail preferences message.');
    }
    // unique checksum for validation
    $unexpiredChecksum = \CRM_Contact_BAO_Contact_Utils::generateChecksum($this->contactID, null, 'inf');
    $primaryEmailUrl = \Civi::settings()->get('wmf_confirm_primary_email_url') .
      '&contact_id='. $this->contactID . '&checksum=' . $unexpiredChecksum .
      '&email_checksum=' . $this->emailChecksum .
      '&email=' . urlencode($this->email);
    [$domainEmailName, $domainEmailAddress] = \CRM_Core_BAO_Domain::getNameAndEmail();

    // Render email template
    $emailTemplate = WorkflowMessage::render(FALSE)
      ->setLanguage($contact['preferred_language'])
      ->setWorkflow(SetPrimaryEmailMessage::WORKFLOW)
      ->setValues([
        'contact' => $contact,
        'contactID' => $this->contactID,
        'url' => $primaryEmailUrl,
        'newEmail' => $this->email,
        'oldEmail' => $contact['email.email'],
        'date' => date('Y-m-d'),
        'time' => date('H:i'),
      ])
      ->execute()->first();

    $emailParams = [
      'html' => $emailTemplate['html'],
      'text' => $emailTemplate['text'],
      'subject' => $emailTemplate['subject'],
      'toEmail' => $this->email,
      'toName' => $contact['first_name'],
      'from' => "$domainEmailName <$domainEmailAddress>",
    ];
    \CRM_Utils_Mail::send($emailParams);
    \Civi::log('wmf')->info("Attempt to update contact $this->contactID's email from {$contact['email.email']} to $this->email, confirmation email sent");
  }
}

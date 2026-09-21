<?php

namespace Civi\WMFQueue;

use Civi\API\Exception\UnauthorizedException;
use Civi\Api4\Activity;
use Civi\Api4\Contact;
use Civi\Api4\Email;
use Civi\Api4\WMFContact;
use Civi\WMFHelper\Activity as ActivityHelper;

/**
 * Consumer for the "verify-email" queue.
 *
 * Expects messages with the following structure:
 *   {
 *     "contact_id": <int>,
 *     "email": <string>,
 *     "checksum": <string>
 *   }
 *
 * Validates the checksum and updates the primary email of the specified contact
 * if it differs from the provided email address.
 *
 * If the contact has been merged, it will attempt to find the new contact ID
 * using getmergedto and update that contact instead.
 */
class VerifyEmailQueueConsumer extends QueueConsumer {

  /**
   * Replacing the primary email address of a contact with the one provided in the message.
   * @param array $message
   * @throws \CRM_Core_Exception
   */
  function processMessage(array $message): void
  {
    $this->validateInput($message);

    $contactID = (int) $message['contact_id'];
    $contact = $this->getContact($contactID);

    if (!$contact) {
      $contactID = $this->getMergedContactID($contactID);
      $contact = $this->getContact($contactID);
    }

    if (strcasecmp($contact['email_primary.email'], $message['email']) !== 0) {
      $this->updatePrimaryEmail($contact, $message['email']);
    } else {
      \Civi::log('wmf')->info("No need to update primary email for contact ID $contactID, already {$message['email']}");
    }
  }

  /**
   * @throws \CRM_Core_Exception
   */
  function validateInput($params): void {
    // check message format
    if (!is_array($params)) {
      throw new \CRM_Core_Exception('Invalid set primary email message format.');
    }
    // check required parameters
    if (!isset($params['email'], $params['contact_id'], $params['checksum'])) {
      throw new \CRM_Core_Exception('Missing parameters in set primary email message.');
    }
    // check parameter formats
    if (
      !filter_var($params['email'], FILTER_VALIDATE_EMAIL) ||
      !preg_match('/^[0-9a-f]+_[0-9]+_inf$/', $params['checksum'])
    ) {
      throw new \CRM_Core_Exception('Invalid parameter types in set primary email message.');
    }
    // check if the non-expired checksum validates
    if (!\CRM_Contact_BAO_Contact_Utils::validChecksum($params['contact_id'], $params['checksum'])) {
      throw new \CRM_Core_Exception('Checksum mismatch.');
    }
  }

  /**
   * @throws UnauthorizedException
   * @throws \CRM_Core_Exception
   */
  private function getContact(int $contactID): ?array
  {
    return Contact::get(FALSE)
      ->addWhere('id', '=', $contactID)
      ->addSelect('email_primary.email')
      ->addSelect('email_primary.id')
      ->addSelect('address_primary.country_id')
      ->addSelect('email_primary.location_type_id')
      ->addSelect('Communication.opt_in')
      ->addSelect('Communication.do_not_solicit')
      ->addSelect('is_opt_out')
      ->addSelect('do_not_email')
      ->addSelect('email_primary.email_settings.snooze_date')
      ->execute()
      ->first();
  }

  /**
   * @throws UnauthorizedException
   * @throws \CRM_Core_Exception
   */
  private function getMergedContactID(int $contactID): int {
    $mergedContactID = (int) Contact::getMergedTo(FALSE)
      ->setContactId($contactID)
      ->execute()
      ->first()['id'];

    if (!$mergedContactID) {
      throw new \CRM_Core_Exception("No contact found with ID $contactID, even after getmergedto");
    }

    \Civi::log('wmf')->info("Contact with ID $contactID has been merged into contact with ID $mergedContactID");
    return $mergedContactID;
  }

  /**
   * @throws \CRM_Core_Exception
   * @throws UnauthorizedException
   */
  private function updatePrimaryEmail(array $contact, string $newEmail): void {
    $oldEmail = $contact['email_primary.email'];
    // demote the primary if location type is not default or EPC
    if (!in_array($contact['email_primary.location_type_id'], [
      \CRM_Core_BAO_LocationType::getDefault()->id,
      \CRM_Core_PseudoConstant::getKey('CRM_Core_BAO_Email', 'location_type_id', 'EmailPreference')
    ])) {
      // Carry the snooze over so a new primary row does not silently un-snooze them
      $updatePrimaryEmail = Email::save(FALSE)
        ->setMatch(['contact_id', 'email', 'location_type_id'])
        ->addRecord([
          'contact_id' => $contact['id'],
          'email' => $newEmail,
          'is_primary' => TRUE,
          'location_type_id:name' => 'EmailPreference',
          'email_settings.snooze_date' => $contact['email_primary.email_settings.snooze_date'],
        ])
        ->execute();
    }
    else {
      $updatePrimaryEmail = Email::update(FALSE)
        ->addWhere('email', '=', $oldEmail)
        ->addWhere('contact_id', '=', $contact['id'])
        ->setValues([
          'email' => $newEmail,
          'location_type_id:name' => 'EmailPreference'
        ])
        ->execute();
    }

    if (!$updatePrimaryEmail->first()) {
      throw new \CRM_Core_Exception("Failed to update {$contact['id']}'s email from $oldEmail to $newEmail.");
    }

    Activity::create(FALSE)
      ->addValue('activity_type_id:name', 'Verify Email And Set As Primary')
      ->addValue('status_id:name', 'Completed')
      ->addValue('subject', "Update Primary Email to {$newEmail}")
      ->addValue('details', "The email address {$oldEmail} has been replaced with {$newEmail} as the primary email address.")
      ->addValue('source_contact_id', $contact['id'])
      ->addValue('source_record_id', $contact['id'])
      ->addValue('activity_date_time', 'now')
      ->execute();

    \Civi::log('wmf')->info("Updated primary email for contact ID {$contact['id']} to $newEmail");

    if (in_array(
      $contact['address_primary.country_id'],
      \Civi::settings()->get('thank_you_double_opt_in_countries')
    )) {
      $doubleOptInEmails = ActivityHelper::getDoubleOptInActivities($contact['id']);
      if (empty($doubleOptInEmails[$newEmail])) {
        Activity::create(FALSE)
          ->addValue('source_record_id', $contact['email_primary.id'])
          ->addValue('source_contact_id', $contact['id'])
          ->addValue('target_contact_id', $contact['id'])
          ->addValue('subject', $newEmail)
          ->addValue('activity_tracking.activity_source', 'Email Preferences')
          ->addValue('activity_type_id:name', 'Double Opt-In')
          ->execute();
      }
    }

    // If we are verifying an email for a contact who is emailable, then make sure
    // any other contact sharing that primary email gets opted in too (and un-snoozed).
    // If the contact is snoozed, then we don't touch any snoozes.
    if ($contact['Communication.opt_in'] !== FALSE
      && !$contact['is_opt_out']
      && !$contact['do_not_email']
      && !$contact['Communication.do_not_solicit']
    ) {
      $snoozeDate = $contact['email_primary.email_settings.snooze_date'];
      $isSnoozed = $snoozeDate && strtotime($snoozeDate) > strtotime('+1 day');
      WMFContact::optIn(FALSE)
        ->setEmail($newEmail)
        ->setCheckSnooze(!$isSnoozed)
        ->execute();
    }
  }
}

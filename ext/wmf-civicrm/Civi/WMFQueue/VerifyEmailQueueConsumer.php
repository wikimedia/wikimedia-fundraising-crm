<?php

namespace Civi\WMFQueue;

use Civi\API\Exception\UnauthorizedException;
use Civi\Api4\Activity;
use Civi\Api4\Contact;
use Civi\Api4\Email;

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

    if ($contact['email_primary.email'] !== $message['email']) {
      $this->updatePrimaryEmail($contact['email_primary.email'], $message['email'], $contactID);
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
  private function updatePrimaryEmail(string $oldEmail, string $newEmail, int $contactID): void {
    $updatePrimaryEmail = Email::update()
      ->addWhere('email', '=', $oldEmail)
      ->addWhere('contact_id', '=', $contactID)
      ->setValues([
          'email' => $newEmail,
          'location_type_id:name' => 'EmailPreference'
      ])
      ->execute();

    if (!$updatePrimaryEmail->first()) {
      throw new \CRM_Core_Exception("Failed to update $contactID's email from $oldEmail to $newEmail.");
    }

    Activity::create(FALSE)
      ->addValue('activity_type_id:name', 'Verify Email And Set As Primary')
      ->addValue('status_id:name', 'Completed')
      ->addValue('subject', "Update Primary Email to {$newEmail}")
      ->addValue('details', "The email address {$oldEmail} has been replaced with {$newEmail} as the primary email address.")
      ->addValue('source_contact_id', $contactID)
      ->addValue('source_record_id', $contactID)
      ->addValue('activity_date_time', 'now')
      ->execute();

    \Civi::log('wmf')->info("Updated primary email for contact ID $contactID to $newEmail");
  }
}

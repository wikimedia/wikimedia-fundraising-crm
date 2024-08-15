<?php

namespace Civi\WMFQueue;

use Civi\Api4\Contact;
use Civi\Api4\WMFContact;
use Civi\WMFQueueMessage\OptInMessage;

class OptInQueueConsumer extends QueueConsumer {

  /**
   * Processes an individual opt-in message. The message just needs
   * the email address. We find all contacts with that email as their
   * primary address and set the opt_in field.
   *
   * @param array $message
   *
   * @throws \CRM_Core_Exception
   * @throws \Civi\WMFException\WMFException
   */
  public function processMessage(array $message) {
    $messageObject = new OptInMessage($message);
    $message = $messageObject->normalize();
    $messageObject->validate();

    $email = $message['email'];

    if (isset($message['contact_id'])) {
      $this->updateContactById((int) $message['contact_id'], $email, $message);
      return;
    }
    else {
      $contacts = $this->getContactsFromEmail($email);
    }

    $new_contact = count($contacts) === 0;
    if ($new_contact) {
      if (!empty($message['last_name'])) {
        $message['Communication.opt_in'] = TRUE;
        $contact_id = WMFContact::save(FALSE)
          ->setMessage($message)
          ->execute()->first()['id'];
        \Civi::log('wmf')->info('opt_in:  New contact created on opt-in: {contact_id}', ['contact_id' => $contact_id]);
      }
      else {
        // look for non-primary email, if found, don't update opt-in
        $civiEmail = civicrm_api3('Email', 'get', ['email' => $email]);
        $new_email = $civiEmail['count'] == 0;
        if ($new_email) {
          // Not enough information for create_contact, create with just email
          $contact = Contact::create(FALSE)
            ->setValues($message + [
              'contact_type' => 'Individual',
              'source' => 'opt-in',
              'email_primary.email' => $message['email'],
            ])
            ->execute()->first();
          \Civi::log('wmf')->info('opt_in: New contact created on opt-in: {contact_id}', ['contact_id' => $contact['id']],);
        }
        else {
          // TODO: They entered an already existing non-primary email, opt them in and make the entered email primary
          \Civi::log('wmf')->info('opt_in: Email already exists with no associated contacts: {email}', ['email' => $email]);
        }
      }
    }
    else {
      $optUsIn = [];
      // Excellent -- we have a collection of contacts to opt in now! :)
      foreach ($contacts as $contact) {
        if ($contact['Communication.opt_in']) {
          \Civi::log('wmf')->notice(
            'opt_in: Contact with ID {contact_id} already opted in for {email}.', ['email' => $email, 'contact_id' => $contact['id']]);
          continue;
        }
        else {
          $optUsIn[] = $contact;
        }

        // And opt them in
        $this->optInContacts($optUsIn, $message);
        $count = count($optUsIn);
        \Civi::log('wmf')->info('opt_in:  {email}: Successfully updated {count} rows.', ['email' => $email, 'count' => $count]);
      }
    }
  }

  /**
   * Obtains a list of arrays of (contact ID, opt_in) for contacts with
   * the given email as their primary contact info.
   *
   * @param string $email The email from the message
   *
   * @return array
   * @throws \CiviCRM_API3_Exception
   */
  private function getContactsFromEmail(string $email): array {
    return (array) Contact::get(FALSE)
      ->addWhere('email_primary.email', '=', $email)
      ->addSelect('Communication.opt_in')
      ->execute();
  }

  /**
   * Updates the Civi database with an opt_in record for the specified contact,
   * and adds new primary email if different from existing.
   *
   * @param int $id Contacts to opt in
   * @param string $email updated email
   * @param array $message
   * @throws \CRM_Core_Exception
   */
  private function updateContactById(int $id, string $email, array $message): void {
    Contact::update(FALSE)
      ->setValues($message)
      ->addWhere('id', '=', $id)
      ->execute();

    $existingEmails = civicrm_api3('Email', 'get', ['contact_id' => $id])['values'];
    $isFound = FALSE;
    foreach ($existingEmails as $existingEmail) {
      if ($existingEmail['email'] === $email) {
        $isFound = TRUE;
        break;
      }
    }

    if (!$isFound) {
      $existingEmails += [
        '0' => [
          'location_type_id' => 4,
          'email' => $email,
          'is_primary' => 1,
        ],
      ];

      $params = [
        'contact_id' => $id,
        'values' => $existingEmails,
      ];
      civicrm_api3('Email', 'replace', $params);
    }

    \Civi::log('wmf')->info('opt_in: Contact updated for opt-in: {id}', ['id' => $id]);
  }

  /**
   * Updates the Civi database with an opt-in record for the specified contacts
   *
   * @param array $contacts Contacts to opt in
   * @param array $message
   *
   * @throws \CRM_Core_Exception
   */
  private function optInContacts(array $contacts, array $message): void {
    foreach ($contacts as $contact) {
      Contact::update(FALSE)
        ->addWhere('id', '=', $contact['id'])
        ->setValues($message)
        ->execute();
    }
  }

}

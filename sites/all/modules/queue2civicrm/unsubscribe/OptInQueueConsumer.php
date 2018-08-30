<?php namespace queue2civicrm\unsubscribe;

use CRM_Core_BAO_CustomField;
use wmf_common\WmfQueueConsumer;
use WmfException;


class OptInQueueConsumer extends WmfQueueConsumer {

  protected $optInCustomFieldName;

  function __construct($queueName, $timeLimit = 0, $messageLimit = 0) {
    parent::__construct($queueName, $timeLimit, $messageLimit);
    $id = CRM_Core_BAO_CustomField::getCustomFieldID(
      'opt_in', 'Communication'
    );
    $this->optInCustomFieldName = "custom_{$id}";
  }

  /**
   * Processes an individual opt-in message. The message just needs
   * the email address. We find all contacts with that email as their
   * primary address and set the opt_in field.
   *
   * @param array $message
   *
   * @throws \WmfException
   * @throws \CiviCRM_API3_Exception
   */
  function processMessage($message) {

    // Sanity checking :)
    if (empty($message['email'])) {
      $error = "Required field not present! Dropping message on floor. Message: " . json_encode($message);
      throw new WmfException(WmfException::UNSUBSCRIBE, $error);
    }

    $email = $message['email'];
    // Find the contact from the contribution ID
    $contacts = $this->getContactsFromEmail($email);

    if (count($contacts) === 0) {
      watchdog('opt_in',
        "$email: No contacts returned for email. Dropping message.",
        [],
        WATCHDOG_NOTICE);
    }
    else {
      $optUsIn = [];
      // Excellent -- we have a collection of contacts to opt in now! :)
      foreach ($contacts as $id => $contact) {
        if ($contact[$this->optInCustomFieldName] == TRUE) {
          watchdog('opt_in',
            "$email: Contact with ID {$contact['id']} already opted in.",
            [],
            WATCHDOG_NOTICE);
          continue;
        }
        else {
          $optUsIn[] = $contact;
        }

        // And opt them in
        $this->optInContacts($optUsIn);
        $count = count($optUsIn);
        watchdog('opt_in', "$email: Successfully updated $count rows.");
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
  function getContactsFromEmail($email) {
    $result = civicrm_api3('Contact', 'get', [
      'email' => $email,
      'email.is_primary',
      'return' => ['id', $this->optInCustomFieldName],
    ]);
    if (empty($result['values'])) {
      return [];
    }
    return $result['values'];
  }

  /**
   * Updates the Civi database with an opt in record for the specified contacts
   *
   * @param array $contacts Contacts to opt in
   *
   * @throws \CiviCRM_API3_Exception
   */
  function optInContacts($contacts) {
    foreach ($contacts as $contact) {
      civicrm_api3('Contact', 'create', [
        'id' => $contact['id'],
        $this->optInCustomFieldName => TRUE,
      ]);
    }
  }

}




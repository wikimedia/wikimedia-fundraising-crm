<?php namespace queue2civicrm\unsubscribe;

use wmf_common\WmfQueueConsumer;
use WmfException;


class OptInQueueConsumer extends WmfQueueConsumer {

  protected $optInCustomFieldName;

  protected $doNotSolicitCustomFieldName;

  function __construct($queueName, $timeLimit = 0, $messageLimit = 0) {
    parent::__construct($queueName, $timeLimit, $messageLimit);
    $commsMap = wmf_civicrm_get_custom_field_map(
      ['opt_in', 'do_not_solicit'], 'Communication'
    );
    $this->optInCustomFieldName = $commsMap['opt_in'];
    $this->doNotSolicitCustomFieldName = $commsMap['do_not_solicit'];
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

    if ( isset( $message['contact_id'] ) && isset($message['contact_hash'] ) ) {
      wmf_civicrm_set_null_id_on_hash_mismatch( $message );
    }

    $email = $message['email'];

    if ( isset( $message['contact_id'] ) ){
      $this->updateContactById( $message['contact_id'], $email );
      return;
    } else {
      $contacts = $this->getContactsFromEmail($email);
    }

    $new_contact = count($contacts) === 0;
    if ($new_contact) {

      if (!empty($message['last_name'])) {
        $message['opt_in'] = TRUE;
        wmf_civicrm_message_create_contact($message);

        $contact_id = $message['contact_id'];
        watchdog('opt_in', "New contact created on opt-in: $contact_id", [],
          WATCHDOG_INFO);
      }
      else {
        // look for non-primary email, if found, don't update opt-in
        $civiEmail = civicrm_api3('Email', 'get', ['email' => $email]);
        $new_email = $civiEmail['count'] == 0;
        if ($new_email) {
          // Not enough information for create_contact, create with just email
          $contactParams = [
            'contact_type' => 'Individual',
            'email' => $email,
            $this->optInCustomFieldName => TRUE,
            'source' => 'opt-in',
          ];

          $contact = civicrm_api3('Contact', 'create', $contactParams);
          watchdog('opt_in', "New contact created on opt-in: {$contact['id']}", [],
            WATCHDOG_INFO);
        }
        else {
          // TODO: They entered an already existing non-primary email, opt them in and make the entered email primary
          watchdog('opt_in', "Email already exists with no associated contacts: {$email}", [],
            WATCHDOG_INFO);
        }
      }
    }
    else {
      $optUsIn = [];
      // Excellent -- we have a collection of contacts to opt in now! :)
      foreach ($contacts as $id => $contact) {
        if ($contact[$this->optInCustomFieldName] == TRUE) {
          watchdog('opt_in',
            "$email: Contact with ID {$contact['id']} already opted in.", [],
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
      'return' => ['id', $this->optInCustomFieldName],
    ]);
    if (empty($result['values'])) {
      return [];
    }
    return $result['values'];
  }

  /**
   * Updates the Civi database with an opt in record for the specified contact,
   * and adds new primary email if different from existing.
   *
   * @param string $id Contacts to opt in
   * @param string $email updated email
   *
   * @throws \CiviCRM_API3_Exception
   */
  function updateContactById( $id, $email ) {

    civicrm_api3('Contact', 'create', [
      'id' => $id,
      $this->optInCustomFieldName => TRUE,
    ]);

    $existingEmails = civicrm_api3('Email', 'get', ['contact_id' => $id])['values'];
    $isFound = FALSE;
    foreach($existingEmails as $existingEmail) {
      if ($existingEmail['email'] === $email) {
        $isFound = TRUE;
        break;
      }
    }

    if (!$isFound){
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
      civicrm_api3('Email', 'replace', $params );
    }

    watchdog('opt_in', "Contact updated for opt-in: $id", [],
      WATCHDOG_INFO);
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
        $this->doNotSolicitCustomFieldName => FALSE,
        'do_not_email' => FALSE,
        'is_opt_out' => FALSE,
      ]);
    }
  }

}




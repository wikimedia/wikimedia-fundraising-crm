<?php

namespace Civi\WMFQueue;

use Civi;
use Civi\WMFException\WMFException;
use Civi\WMFHook\PreferencesLink;
use Civi\WorkflowMessage\NewChecksumLinkMessage;

class NewChecksumLinkQueueConsumer extends QueueConsumer {

  /**
   * Sends out emails with new links to the email preferences center or recurring upgrade page.
   *
   * @param array $message
   *
   * @throws \Civi\WMFException\WMFException
   */
  function processMessage(array $message) {
    $contactID = $message['contactID'];
    $contact = Civi\Api4\Contact::get(FALSE)
      ->addWhere('id', '=', $contactID)
      ->addSelect('preferred_language')
      ->addSelect('first_name')
      ->addSelect('display_name')
      ->addSelect('email_primary.email')
      ->execute()->first();

    if (!$contact) {
      throw new WMFException(
        WMFException::INVALID_MESSAGE,
        "No contact found with id $contactID"
      );
    }

    switch ($message['page']) {
      case 'RecurUpgrade':
        $recurringUpgradeBaseUrl = (string) \Civi::settings()->get('wmf_recurring_upgrade_url');
        $checksum = \CRM_Contact_BAO_Contact_Utils::generateChecksum($contactID);
        $url = PreferencesLink::addContactAndChecksumToUrl($recurringUpgradeBaseUrl, $contactID, $checksum);
        break;
      case 'EmailPreferences':
        // TODO: consider subpages, e.g. opt in. For now we only serve the main email preferences page, so this is OK.
        $url = PreferencesLink::getPreferenceUrl($contactID);
        break;
      default:
        throw new WMFException(
          WMFException::INVALID_MESSAGE,
          "Bad 'page' parameter {$message['page']}"
        );
    }

    $email = Civi\Api4\WorkflowMessage::render(FALSE)
      ->setLanguage($contact['preferred_language'])
      ->setWorkflow(NewChecksumLinkMessage::WORKFLOW)
      ->setValues([
        'contact' => $contact,
        'contactID' => $contactID,
        'url' => $url
      ])
      ->execute()->first();

    [$domainEmailName, $domainEmailAddress] = \CRM_Core_BAO_Domain::getNameAndEmail();
    $params = [
      'html' => $email['html'] ?? NULL,
      'text' => $email['text'] ?? NULL,
      'subject' => $email['subject'],
      'toEmail' => $contact['email_primary.email'],
      'toName' => $contact['display_name'],
      'from' => "$domainEmailName <$domainEmailAddress>",
    ];
    \CRM_Utils_Mail::send($params);
  }

}

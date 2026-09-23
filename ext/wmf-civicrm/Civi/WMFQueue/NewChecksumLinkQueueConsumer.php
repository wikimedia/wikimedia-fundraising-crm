<?php

namespace Civi\WMFQueue;

use Civi;
use Civi\Api4\Activity;
use Civi\Api4\Contact;
use Civi\Api4\Email;
use Civi\Omnimail\MailFactory;
use Civi\WMFException\WMFException;
use Civi\WMFHook\PreferencesLink;
use Civi\WMFThankYou\From;
use Civi\WorkflowMessage\NewChecksumLinkMessage;

class NewChecksumLinkQueueConsumer extends QueueConsumer {

  /**
   * Setting holding the plain url of each page we can send a link to.
   */
  private const PAGE_URL_SETTINGS = [
    'RecurUpgrade' => 'wmf_recurring_upgrade_url',
    'EmailPreferences' => 'wmf_email_preferences_url',
    'DonorPortal' => 'wmf_donor_portal_url',
  ];

  /**
   * Value of wmf_donor.donor_segment_overall for a contact who has never donated.
   */
  private const NON_DONOR_SEGMENT = 990;

  /**
   * Sends out emails with new links to the email preferences center, donor portal
   * or recurring upgrade page.
   *
   * If we can't tell whose account the email belongs to, we send an email saying so.
   *
   * @param array $message
   *
   * @throws \Civi\WMFException\WMFException
   */
  function processMessage(array $message) {
    if (!isset(self::PAGE_URL_SETTINGS[$message['page']])) {
      throw new WMFException(
        WMFException::INVALID_MESSAGE,
        "Bad 'page' parameter {$message['page']}"
      );
    }

    $contactGet = Contact::get(FALSE)
      ->addSelect('preferred_language')
      ->addSelect('first_name')
      ->addSelect('display_name')
      ->addSelect('email_primary.email');

    if ($message['page'] === 'DonorPortal') {
      // Only the donor portal cares whether they have ever donated.
      $contactGet->addSelect('wmf_donor.donor_segment_overall');
    }

    if (!empty($message['contactID'])) {
      $identifier = 'id ' . $message['contactID'];
      $contactGet->addWhere('id', '=', $message['contactID']);
    }
    elseif (!empty($message['email'])) {
      $identifier = 'email ' . $message['email'];
      $contactGet->addWhere('email_primary.email', '=', $message['email'])
        ->addOrderBy('modified_date', 'DESC');
    }
    else {
      throw new WMFException(
        WMFException::INVALID_MESSAGE,
        'Donor preferences link request message needs contact ID or email'
      );
    }

    $contacts = $contactGet->execute();
    $contact = $contacts->first();
    $isSecondaryEmail = FALSE;

    if (!$contact && !empty($message['email'])) {
      // Not a primary email, so use it if it is a secondary email on exactly one contact.
      $contactIDs = Email::get(FALSE)
        ->addWhere('email', '=', $message['email'])
        ->addWhere('contact_id.is_deleted', '=', FALSE)
        ->addSelect('contact_id')
        ->addGroupBy('contact_id')
        ->execute()->column('contact_id');
      if (count($contactIDs) === 1) {
        $contacts = $contactGet->setWhere([['id', '=', $contactIDs[0]]])->execute();
        $contact = $contacts->first();
        $isSecondaryEmail = TRUE;
      }
    }

    if ($contact && $message['page'] === 'DonorPortal' && !$this->hasDonated($contacts)) {
      // The donor portal has nothing to show someone who has never donated.
      $contact = NULL;
    }

    if (!$contact) {
      Civi::log()->warning("New link queue consumer: No account found with $identifier");
      if (!empty($message['email'])) {
        $this->sendAccountNotFound($message['email'], $message['page']);
      }
      return;
    }

    $contactID = $contact['id'];
    // The donor portal can't change an email address, but the preferences center can.
    $preferencesUrl = ($isSecondaryEmail && $message['page'] === 'DonorPortal')
      ? PreferencesLink::getPreferenceUrl($contactID) : NULL;

    switch ($message['page']) {
      case 'RecurUpgrade':
        $recurringUpgradeBaseUrl = (string) \Civi::settings()->get('wmf_recurring_upgrade_url');
        $checksum = \CRM_Contact_BAO_Contact_Utils::generateChecksum($contactID);
        $url = PreferencesLink::addContactAndChecksumToUrl($recurringUpgradeBaseUrl, $contactID, $checksum);
        break;
      case 'EmailPreferences':
        // This gets us a link to Special:
        $url = PreferencesLink::getPreferenceUrl($contactID);
        if (($message['subpage'] ?? NULL) == 'optIn') {
          // TODO: better way to do subpages, e.g. optIn
          $url = str_replace('emailPreferences', 'optIn', $url);
        }
        break;
      case 'DonorPortal':
        $donorPortalBaseUrl = (string) \Civi::settings()->get('wmf_donor_portal_url');
        $checksum = \CRM_Contact_BAO_Contact_Utils::generateChecksum($contactID);
        $url = PreferencesLink::addContactAndChecksumToUrl($donorPortalBaseUrl, $contactID, $checksum);
        break;
    }

    $email = Civi\Api4\WorkflowMessage::render(FALSE)
      ->setLanguage($contact['preferred_language'])
      ->setWorkflow(NewChecksumLinkMessage::WORKFLOW)
      ->setValues([
        'contact' => $contact,
        'contactID' => $contactID,
        'url' => $url,
        'targetPage' => $message['page'],
        'isSecondaryEmail' => $isSecondaryEmail,
        'preferencesUrl' => $preferencesUrl,
      ])
      ->execute()->first();

    $fromName = From::getFromName(NewChecksumLinkMessage::WORKFLOW);
    $fromAddress = From::getFromAddress(NewChecksumLinkMessage::WORKFLOW);
    $params = [
      'html' => $email['html'] ?? NULL,
      'subject' => $email['subject'],
      'to_address' => $isSecondaryEmail ? $message['email'] : $contact['email_primary.email'],
      'to_name' => $contact['display_name'],
      'from_address' => $fromAddress,
      'from_name' => $fromName,
    ];
    $success = MailFactory::singleton()->getMailer()->send($params);
    if ($success) {
      $details = 'Requested page: ' . $message['page'];
      if ($isSecondaryEmail) {
        $details .= '. Sent secondary email version.';
      }
      Activity::create(FALSE)->setValues([
        'target_contact_id' => $contactID,
        'source_contact_id' => $contactID,
        'subject' => $email['subject'],
        'details' => $details,
        'activity_type_id:name' => 'Email',
        'activity_date_time' => 'now',
        'Email.Workflow' => NewChecksumLinkMessage::WORKFLOW,
      ])->execute();
    }
  }

  /**
   * Tell the requester we can't find an account for their email.
   *
   * There is no link to any account in this email: we found no contact, or found
   * several with the requested email as secondary.
   *
   * @param string $toAddress
   * @param string $page
   */
  private function sendAccountNotFound(string $toAddress, string $page): void {
    $email = Civi\Api4\WorkflowMessage::render(FALSE)
      ->setWorkflow(NewChecksumLinkMessage::WORKFLOW)
      ->setValues([
        // No contact, so the template uses a generic greeting.
        'contact' => NULL,
        'targetPage' => $page,
        'accountFound' => FALSE,
        // Without a checksum this page shows the form for requesting a new link.
        'retryUrl' => (string) \Civi::settings()->get(self::PAGE_URL_SETTINGS[$page]),
      ])
      ->execute()->first();

    MailFactory::singleton()->getMailer()->send([
      'html' => $email['html'] ?? NULL,
      'subject' => $email['subject'],
      'to_address' => $toAddress,
      'to_name' => '',
      'from_address' => From::getFromAddress(NewChecksumLinkMessage::WORKFLOW),
      'from_name' => From::getFromName(NewChecksumLinkMessage::WORKFLOW),
    ]);
  }

  /**
   * Has any of these contacts ever donated?
   *
   * When we match on a primary email, every contact sharing it is in $contacts,
   * and the donor portal shows donations from all of them.
   *
   * @param iterable $contacts
   *
   * @return bool
   */
  private function hasDonated(iterable $contacts): bool {
    foreach ($contacts as $contact) {
      $segment = $contact['wmf_donor.donor_segment_overall'] ?? NULL;
      // No segment at all means they have never donated either.
      if ($segment !== NULL && (int) $segment !== self::NON_DONOR_SEGMENT) {
        return TRUE;
      }
    }
    return FALSE;
  }

}

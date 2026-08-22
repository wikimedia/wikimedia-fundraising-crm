<?php

namespace Civi\WMFQueue;

use Civi\API\Exception\UnauthorizedException;
use Civi\Api4\Activity;
use Civi\Api4\Contact;
use Civi\Api4\DoubleOptIn;
use Civi\Api4\Email;
use Civi\Api4\Generic\Result;
use Civi\Api4\WMFContact;
use Civi\WMFException\WMFException;
use SmashPig\Core\UtcDate;

/**
 * Creates new contacts from a lead generation form
 */
class LeadGenerationQueueConsumer extends TransactionalQueueConsumer {

  const ACTIVITY_TYPE_NAME = 'Lead Generation Signup';

  /**
   * @inheritDoc
   *
   * @param array $message
   *
   * @throws WMFException
   * @throws \CRM_Core_Exception
   * @throws \Throwable
   */
  public function processMessage(array $message): void {
    $this->validateMessage($message);
    $contacts = $this->getContacts($message['email']);
    $needsDoubleOptIn = FALSE;
    if ($contacts->count() > 0) {
      $needsDoubleOptIn = !WMFContact::bulkEmailable(FALSE)
        ->setEmail($message['email'])
        ->setCheckSnooze(FALSE)
        ->execute()->first();
      if (array_filter($contacts->column('email_primary.email_settings.snooze_date'))) {
        $this->cancelSnooze($message['email']);
      }
    }
    else {
      $contacts = $this->createContact($message);
      $needsDoubleOptIn = TRUE;
    }
    $this->addActivity($contacts, $message);
    if ($needsDoubleOptIn) {
      $this->sendDoubleOptInEmail($contacts, $message);
    }
  }

  /**
   * @throws WMFException
   */
  protected function validateMessage(array $message): void {
    foreach (['email', 'leadgen_source'] as $field) {
      if (empty($message[$field])) {
        throw new WMFException(WMFException::MISSING_MANDATORY_DATA, "Missing $field");
      }
    }
  }

  /**
   * @throws \CRM_Core_Exception
   */
  protected function getContacts( string $email): Result {
    return Contact::get(FALSE)
      ->addSelect('id', 'display_name', 'email_primary.email_settings.snooze_date')
      ->addWhere('email_primary.email', '=', $email)
      ->addWhere('is_deleted', '=', 0)
      ->addOrderBy('wmf_donor.all_funds_last_donation_date', 'DESC')
      ->addOrderBy('id', 'ASC')
      ->execute();
  }

  /**
   * For each primary email that has a snooze later than tomorrow, change to tomorrow
   * (that date gets pushed to Acoustic by the omnimail_civicrm_customPre hook).
   *
   * @throws \CRM_Core_Exception
   */
  protected function cancelSnooze(string $email): void {
    $primaryEmails = Email::get(FALSE)
      ->addSelect('email_settings.snooze_date')
      ->addWhere('email', '=', $email)
      ->addWhere('is_primary', '=', TRUE)
      ->execute();
    foreach ($primaryEmails as $primaryEmail) {
      $snoozeDate = $primaryEmail['email_settings.snooze_date'];
      if (isset($snoozeDate) && strtotime($snoozeDate) > strtotime('+1 day')) {
        Email::update(FALSE)
          ->addValue('email_settings.snooze_date', date('Y-m-d', strtotime('+1 day')))
          ->addWhere('id', '=', $primaryEmail['id'])
          ->execute();
      }
    }
  }

  /**
   * @throws \CRM_Core_Exception
   */
  protected function createContact(array $message): Result {
    return Contact::create(FALSE)
      ->addValue('email_primary.email', $message['email'])
      ->addValue('source', 'Leadgen: ' . $message['leadgen_source'])
      ->addValue('Communication.opt_in', FALSE)
      ->execute();
  }

  /**
   * @throws \CRM_Core_Exception
   * @throws \Exception
   */
  protected function addActivity(Result $contacts, array $message): void {
    Activity::create(FALSE)
      ->addValue('activity_type_id:name', self::ACTIVITY_TYPE_NAME)
      ->addValue('subject', "Lead generation signup from {$message['leadgen_source']}")
      ->addValue('target_contact_id', $contacts->column('id'))
      ->addValue('source_contact_id', $contacts->first()['id'])
      ->addValue('status_id:name', 'Completed')
      ->addValue('details', "The email address {$message['email']} was entered into a lead generation form with source {$message['leadgen_source']}.")
      ->addValue('activity_date_time', UtcDate::getUtcDatabaseString($message['source_enqueued_time']))
      ->addValue('Source.Source', $message['leadgen_source'])
      ->execute();
  }

  /**
   * @throws \CRM_Core_Exception
   * @throws \Throwable
   */
  protected function sendDoubleOptInEmail(Result $contacts, array $message): void {
    $contact = $contacts->first();
    DoubleOptIn::send(FALSE)
      ->setDisplayName($contact['display_name'] ?? $message['email'])
      ->setContactID($contact['id'])
      ->setEmail($message['email'])
      ->setWorkflow('double_opt_in_lead_gen')
      ->execute();
  }
}

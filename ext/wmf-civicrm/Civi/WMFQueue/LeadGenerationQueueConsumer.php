<?php

namespace Civi\WMFQueue;

use Civi\API\Exception\UnauthorizedException;
use Civi\Api4\Activity;
use Civi\Api4\Contact;
use Civi\Api4\Generic\Result;
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
   * @throws UnauthorizedException
   * @throws WMFException
   * @throws \CRM_Core_Exception
   */
  public function processMessage(array $message): void {
    $this->validateMessage($message);
    $contacts = $this->getAndOptInContacts($message['email']);
    if ($contacts->count() === 0) {
      $contacts = $this->createContact($message);
    }
    $this->addActivity($contacts, $message);
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
   * @throws UnauthorizedException
   * @throws \CRM_Core_Exception
   */
  protected function getAndOptInContacts( string $email): Result {
    $contacts = Contact::get(FALSE)
      ->addSelect('Communication.opt_in')
      ->addWhere('email_primary.email', '=', $email)
      ->addWhere('is_deleted', '=', 0)
      ->execute();

    $optIns = [];
    foreach ($contacts as $contact) {
      if (!$contact['Communication.opt_in']) {
        $optIns[] = $contact['id'];
      }
    }
    if ($optIns) {
      Contact::update(FALSE)
        ->addWhere('id', 'IN', $optIns)
        ->addValue('Communication.opt_in', TRUE)
        ->execute();
    }

    return $contacts;
  }

  /**
   * @throws UnauthorizedException
   * @throws \CRM_Core_Exception
   */
  protected function createContact(array $message): Result {
    return Contact::create(FALSE)
      ->addValue('email_primary.email', $message['email'])
      ->addValue('source', 'Leadgen: ' . $message['leadgen_source'])
      ->addValue('Communication.opt_in', TRUE)
      ->execute();
  }

  /**
   * @throws \CRM_Core_Exception
   * @throws UnauthorizedException
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

}

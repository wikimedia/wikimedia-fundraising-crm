<?php

namespace Civi\Api4\Action\WMFDataManagement;

use Civi\Api4\Contact;
use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\Generic\Result;

/**
 * Class ArchiveThankYou.
 *
 * Delete details from old thank you emails.
 *
 * @method setLimit(int $limit) Set the number of activities to hit in the run.
 * @method getLimit(): int Get the number of activities
 * @method setEndDateTime(string $endDateTime) Set the time to purge up to.
 * @method getEndDateTime(): string Get the time to purge up to.
 *
 * @package Civi\Api4
 */
class DeleteDeletedContacts extends AbstractAction {

  /**
   * Limit for run.
   *
   * @var int
   */
  protected $limit = 10000;

  /**
   * Date to finish at - a strtotime-able value.
   *
   * @var string
   */
  protected $endDateTime = '1 year ago';

  /**
   * @inheritDoc
   *
   * @param \Civi\Api4\Generic\Result $result
   *
   * @throws \CRM_Core_Exception
   */
  public function _run(Result $result): void {
    $last_modified_date = \Civi::settings()->get('wmf_last_delete_deleted_contact_modified_date');
    $contactsGet = Contact::get($this->getCheckPermissions())
      ->addWhere('is_deleted', '=', TRUE)
      ->setLimit($this->getLimit())
      ->addSelect('id', 'modified_date', 'is_deleted')
      ->addOrderBy('modified_date');

    if ($last_modified_date) {
      $contactsGet->addWhere('modified_date', 'BETWEEN', [$last_modified_date, date('Y-m-d H:i:s', strtotime($this->getEndDateTime()))]);
    }
    else {
      $contactsGet->addWhere('modified_date', '<', date('Y-m-d H:i:s', strtotime($this->getEndDateTime())));
    }
    $contacts = $contactsGet->execute()->indexBy('id');
    $modifiedDate = '';
    $count = 0;
    foreach ($contacts as $contactID => $contact) {
      $modifiedDate = $contact['modified_date'];
      try {
        Contact::delete($this->getCheckPermissions())
          ->setUseTrash(FALSE)
          ->addWhere('id', '=', $contactID)
          ->execute();
        $count++;
      }
      catch (\CRM_Core_Exception $e) {
        \Civi::log('wmf')->warning('civicrm_cleanup_logs: This contact id is not able to be deleted: {contact_id}', ['contact_id' => $contactID]);
      }
    }
    if ($modifiedDate) {
      \Civi::settings()->set('wmf_last_delete_deleted_contact_modified_date', $modifiedDate);
    }
    $message = !$modifiedDate ? "No deleted contact found to delete" : "Deleted $count deleted contacts, most recent modified_date: " . $modifiedDate;
    \Civi::log('wmf')->notice('civicrm_cleanup_logs: {message}', ['message' => $message]);
  }

}

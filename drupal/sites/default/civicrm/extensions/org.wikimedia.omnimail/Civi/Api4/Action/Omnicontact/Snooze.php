<?php

namespace Civi\Api4\Action\Omnicontact;

use Civi\Api4\Activity;
use Civi\Api4\Contact;
use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\Generic\Result;

/**
 * Snooze jobs are not run directly. They are queued and run as background tasks
 * by coworker(https://lab.civicrm.org/dev/coworker)
 *
 *
 * @method string|null getEmail()
 * @method $this setEmail(?string $email)
 * @method $this setContactID(?string $contactID)
 * @method string|null getSnoozeDate()
 * @method $this setSnoozeDate(?string $contactID)
 * @method $this setDatabaseID(int $databaseID)
 * @method $this setMailProvider(string $mailProvider) Generally Silverpop....
 * @method string getMailProvider()
 */
class Snooze extends AbstractAction {

  protected $email;
  protected $contactID;
  protected $databaseID;
  protected $snoozeDate;

  /**
   * @var string
   */
  protected $mailProvider = 'Silverpop';

  /**
   * @throws \CRM_Core_Exception
   */
  public function _run(Result $result): void {
    $queueName = 'omni-snooze';
    $queue = \Civi::queue($queueName, [
      'type' => 'Sql',
      'runner' => 'task',
      'retry_limit' => 3,
      'retry_interval' => 20,
      'error' => 'abort',
    ]);
    $email = $this->getEmail();
    if (!$email) {
      throw new \CRM_Core_Exception('Email required.');
    }
    if ($this->contactID) {
      $contact_id = $this->contactID;
      $activity_id = Activity::create(FALSE)
        ->addValue('activity_type_id:name', 'EmailSnoozed')
        ->addValue('status_id:name', 'Scheduled')
        ->addValue('subject', "Email snooze scheduled - until " . date('Y-m-d', strtotime($this->getSnoozeDate())))
        ->addValue('details', "Snoozing email - $email")
        ->addValue('source_contact_id', $contact_id)
        ->addValue('source_record_id', $contact_id)
        ->addValue('activity_date_time', 'now')
        ->execute()
        ->first()['id'] ?? NULL;
    }
    $queue->createItem(new \CRM_Queue_Task('civicrm_api4_queue',
      [
        'Omnicontact',
        'create',
        [
          'databaseID' => $this->getDatabaseID(),
          'email' => $this->getEmail(),
          'checkPermissions' => $this->getCheckPermissions(),
          'values' => [
            'snooze_end_date' => date('Y-m-d H:i:s', strtotime($this->getSnoozeDate())),
            'activity_id' => $activity_id,
          ],
        ],
      ],
      'Snooze contact'
    ), ['weight' => 100]);

    $result[] = [
      'queue_name' => $queueName,
      'email' => $this->getEmail(),
      'snoozeDate' => date('Y-m-d H:i:s', strtotime($this->getSnoozeDate())),
    ];
  }

  /**
   * Get the remote database ID.
   *
   * @return int
   */
  public function getDatabaseID(): int {
    if (!$this->databaseID) {
      $this->databaseID = \Civi::settings()->get('omnimail_credentials')[$this->getMailProvider()]['database_id'][0];
    }
    return $this->databaseID;
  }

}

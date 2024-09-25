<?php
namespace Civi\Api4\Action\Omnigroup;

use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\Generic\Result;
use Civi\Api4\Contact;
use Civi\Api4\Omnicontact;
use Civi\Api4\Omnigroup;
use Civi\Api4\Queue;
use GuzzleHttp\Client;

/**
 * Group push.
 *
 * Push a CiviCRM group to the external provider.
 *
 * Provided by the omnimail extension.
 *
 * @method $this setGroupID(int $groupID)
 * @method int getGroupID() Get group ID.
 * @method $this setDatabaseID(int $databaseID)
 * @method $this setIsPublic(bool $isListPublic)
 * @method bool getIsPublic()
 * @method $this setMailProvider(string $mailProvider) Generally Silverpop....
 * @method string getMailProvider()
 * @method $this setClient(Client$client) Generally Silverpop....
 * @method null|Client getClient()
 *
 * @package Civi\Api4
 */
class Push extends AbstractAction {

  /**
   * ID of the group to push up.
   *
   * @var int
   */
  protected $groupID;

  /**
   * @var object
   */
  protected $client;

  /**
   * @required
   *
   * @var int
   */
  protected $databaseID;

  /**
   * @var string
   */
  protected $mailProvider = 'Silverpop';

  /**
   * Is the list to be visible to other acoustic users.
   *
   * @var bool
   */
  protected $isPublic = TRUE;

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

  /**
   * @inheritDoc
   *
   * @param \Civi\Api4\Generic\Result $result
   *
   * @throws \CRM_Core_Exception
   */
  public function _run(Result $result) {
    $queue = \Civi::queue('omni-sync-group-' . $this->getGroupID(), [
      'type' => 'Sql',
      'retry_limit' => 3,
      'retry_interval' => 20,
    ]);
    $queue->createItem(new \CRM_Queue_Task('civicrm_api4_queue',
      [
        'Omnigroup',
        'create',
        [
          'groupID' => $this->getGroupID(),
          'mailProvider' => $this->getMailProvider(),
          'databaseID' => $this->getDatabaseID(),
        ]
      ],
      'Create remote group'
    ));
    $groupMembers = Contact::get()
      ->addWhere('groups', 'IN', [$this->getGroupID()])
      ->addJoin('Email AS email', 'INNER', NULL,
        ['id', '=', 'email.contact_id'],
        ['email.is_primary', '=', TRUE]
      )
      ->setSelect(['id', 'email.email'])->execute();
    foreach ($groupMembers as $groupMember) {
      $queue->createItem(new \CRM_Queue_Task('civicrm_api4_queue',
        [
          'Omnicontact',
          'create',
          [
            'groupID' => $this->getGroupID(),
            'mailProvider' => $this->getMailProvider(),
            'databaseID' => $this->getDatabaseID(),
            'email' => $groupMember['email.email'],
          ]
        ], 'Push contact to Acoustic: ' . $groupMember['email.email']), ['weight' => 1]);
    }
    $runner = new \CRM_Queue_Runner([
      'title' => ts('Sync to Acoustic'),
      'queue' => $queue,
      'errorMode' => \CRM_Queue_Runner::ERROR_ABORT,
      'onEndUrl' => \CRM_Utils_System::url('civicrm/group', ['reset=1', 'action=update', 'id=' . $this->getGroupID()]),
    ]);
    $runner->runAllViaWeb();
  }

  public function fields() {
    return [];
  }

}

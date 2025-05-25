<?php
namespace Civi\Api4\Action\Omnigroup;

use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\Generic\Result;
use Civi\Api4\Group;
use GuzzleHttp\Client;

/**
 *  Class Check.
 *
 * Provided by the  extension.
 *
 * @method $this setGroupID(string $listName)
 * @method string getGroupID() Get list name.
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
class Create extends AbstractAction {

  /**
   * @var object
   */
  protected $client;

  /**
   * @required
   *
   * @var int
   */
  protected $groupID;

  /**
   * For staging use id from docs - buildkit should configure this.
   *
   * https://wikitech.wikimedia.org/wiki/Fundraising/Data_and_Integrated_Processes/Acoustic_Integration#Sandbox
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
   * @inheritDoc
   *
   * @param \Civi\Api4\Generic\Result $result
   *
   * @throws \CRM_Core_Exception
   */
  public function _run(Result $result): void {
    $omnigroup = new \CRM_Omnimail_Omnigroup([
      'mail_provider' => $this->getMailProvider(),
    ]);
    $group = Group::get($this->getCheckPermissions())
      ->addWhere('id', '=', $this->getGroupID())
      ->setSelect(['Group_Metadata.remote_group_identifier', 'title'])
      ->execute()->first();

    $remoteGroupID = $group['Group_Metadata.remote_group_identifier'] ?? NULL;
    if (!$remoteGroupID) {
      $remoteGroup = $omnigroup->create([
        'client' => $this->getClient(),
        'mail_provider' => $this->getMailProvider(),
        'group_id' => $this->getGroupID(),
        'database_id' => $this->getDatabaseID(),
        'visibility' => (int) $this->getIsPublic(),
        'check_permissions' => $this->getCheckPermissions(),
      ]);
      $remoteGroupID = $remoteGroup['list_id'];
      $result[$this->getGroupID()] = $remoteGroup;

      Group::update($this->getCheckPermissions())
        ->addWhere('id', '=', $this->getGroupID())
        ->addValue('Group_Metadata.remote_group_identifier', $remoteGroupID)
        ->execute();
    }
    $result[$this->getGroupID()]['Group_Metadata.remote_group_identifier'] = $remoteGroupID;
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

  /**
   * @return array
   */
  public function fields(): array {
    return [];
  }

}

<?php
namespace Civi\Api4\Action\Group;

use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\Generic\AbstractBatchAction;
use Civi\Api4\Generic\BasicBatchAction;
use Civi\Api4\Generic\Result;
use Civi\Api4\Contact;
use Civi\Api4\Omnigroup;
use Civi\Api4\WMFAudit;
use GuzzleHttp\Client;

/**
 * Group push.
 *
 * Push a CiviCRM group to the external provider.
 *
 * Provided by the omnimail extension.
 *
 * @method $this setGroupID(int $groupID)
 * @method $this setDatabaseID(int $databaseID)
 * @method $this setMailProvider(string $mailProvider) Generally Silverpop....
 *
 * @package Civi\Api4
 */
class RemotePush extends BasicBatchAction {

  /**
   * @var string
   */
  protected $mailProvider = 'acoustic';

  /**
   * ID of the group to push up.
   *
   * @var int
   */
  protected $groupID;

  /**
   * @var int
   */
  protected $databaseID;

  public function getMailProvider(): string {
    return $this->mailProvider === 'acoustic' ? 'Silverpop' : $this->mailProvider;
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

  public function doTask($item): array {
    return Omnigroup::push($this->checkPermissions)
      ->setGroupID($item['id'])
      ->setDatabaseID($this->getDatabaseID())
      ->setMailProvider($this->getMailProvider())
      ->execute()->first() ?? [];
  }

  public function fields() {
    return [];
  }

}

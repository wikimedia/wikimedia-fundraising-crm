<?php
namespace Civi\Api4\Action\Omnigroup;

use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\Generic\Result;
use \CRM_Omnimail_ExtensionUtil as E;
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
   * @inheritDoc
   *
   * @param \Civi\Api4\Generic\Result $result
   *
   * @throws \API_Exception
   * @throws \CiviCRM_API3_Exception
   */
  public function _run(Result $result): void {
    $omnigroup = new \CRM_Omnimail_Omnigroup([
      'mail_provider' => $this->getMailProvider(),
    ]);
    $result[] = $omnigroup->create([
      'client' => $this->getClient(),
      'mail_provider' => $this->getMailProvider(),
      'group_id' => $this->getGroupID(),
      'database_id' => $this->getDatabaseID(),
      'visibility' => (int) $this->getIsPublic(),
      'check_permissions' => $this->getCheckPermissions(),
    ]);
  }

  /**
   * Get the remote database ID.
   *
   * @return int
   *
   * @throws \API_Exception
   */
  public function getDataBaseID(): int {
    if ($this->databaseID) {
      return $this->databaseID;
    }
    $databases = \Civi::settings()->get('omnimail_credentials')[$this->getMailProvider()]['database_id'];
    if (empty($databases)) {
      throw new \API_Exception('Could not determine database - for staging use id from docs - https://wikitech.wikimedia.org/wiki/Fundraising/Data_and_Integrated_Processes/Acoustic_Integration#Sandbox');
    }
    return reset($databases);
  }

  /**
   * @return array
   */
  public function fields(): array {
    return [];
  }

}

<?php
namespace Civi\Api4\Action\Omnicontact;

use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\Generic\Result;
use \CRM_Omnimail_ExtensionUtil as E;
use GuzzleHttp\Client;

/**
 *  Class Check.
 *
 * Provided by the  extension.
 *
 * @method $this setDatabaseID(int $databaseID)
 * @method $this setEmail(string|null $email)
 * @method string|null getEmail()
 * @method $this setContactID(int $contactID)
 * @method int getContactID()
 * @method $this setGroupIdentifier(array $groupIdentifier)
 * @method array getGroupIdentifier()
 * @method $this setMailProvider(string $mailProvider) Generally Silverpop....
 * @method string getMailProvider()
 * @method $this setClient(Client$client) Generally Silverpop....
 * @method null|Client getClient()
 *
 * @package Civi\Api4
 */
class Get extends AbstractAction {

  /**
   * @var object
   */
  protected $client;

  /**
   * @var array
   */
  protected $groupIdentifier;

  /**
   * @var int
   */
  protected $contactID;

  /**
   * @var string
   */
  protected $email;

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
   * Get the remote database ID.
   *
   * @return int
   *
   * @throws \CRM_Core_Exception
   */
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
    $omniObject = new \CRM_Omnimail_Omnicontact([
      'mail_provider' => $this->getMailProvider(),
    ]);
    $result[] = $omniObject->get([
      'client' => $this->getClient(),
      'mail_provider' => $this->getMailProvider(),
      'database_id' => $this->getDatabaseID(),
      'email' => $this->getEmail(),
      'contact_id' => $this->getContactID(),
      'group_identifier' => $this->getGroupIdentifier(),
      'check_permissions' => $this->getCheckPermissions(),
    ]);
  }

  public function fields(): array {
    return [];
  }

}

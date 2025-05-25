<?php
namespace Civi\Api4\Action\Omnicontact;

use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\Generic\Result;
use GuzzleHttp\Client;
use CRM_Omnimail_ExtensionUtil as E;

/**
 *  Class Check.
 *
 * Provided by the  extension.
 *
 * @method $this setDatabaseID(int $databaseID)
 * @method $this setEmail(bool $email)
 * @method bool getEmail()
 * @method $this setValues(array $values)
 * @method array getValues()
 * @method array getGroupID()
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
   * @var array
   */
  protected $groupID;

  /**
   * @var string
   */
  protected $email;

  /**
   * @var array
   */
  protected $values = [];

  /**
   *
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
   * Set the group ID.
   *
   * @param int|array $groupID
   */
  public function setGroupID($groupID): self {
    $this->groupID = (array) $groupID;
    return $this;
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
    $result[] = $omniObject->create([
      'client' => $this->getClient(),
      'mail_provider' => $this->getMailProvider(),
      'database_id' => $this->getDatabaseID(),
      'email' => $this->getEmail(),
      'group_id' => $this->getGroupID(),
      'values' => $this->getValues(),
      'snooze_end_date' => $this->getValues()['snooze_end_date'] ?? NULL,
      'check_permissions' => $this->getCheckPermissions(),
    ]);
  }

  public function fields(): array {
    return [
      [
        'name' => 'snooze_end_date',
        'required' => FALSE,
        'description' => E::ts('Snooze End Date'),
        'data_type' => 'Datetime',
      ],
    ];
  }

}

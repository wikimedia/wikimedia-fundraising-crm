<?php
namespace Civi\Api4\Action\Omnicontact;

use Civi\Api4\Contact;
use Civi\Api4\Generic\AbstractCreateAction;
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
 * @method $this setRecipientID(?int $recipientID)
 * @method bool getEmail()
 * @method array getGroupID()
 * @method int getGroupIdentifier()
 * @method $this setGroupIdentifier(array $groupIdentifier)
 * @method int getContactID()
 * @method $this setContactID(?int $contactID)
 * @method $this setMailProvider(string $mailProvider) Generally Silverpop....
 * @method string getMailProvider()
 * @method $this setClient(Client$client) Generally Silverpop....
 * @method null|Client getClient()
 *
 * @package Civi\Api4
 */
class Create extends AbstractCreateAction {

  /**
   * @var object
   */
  protected $client;

  /**
   * @var array
   */
  protected $groupID;

  /**
   * Acoustic contact lists ids to add the contact to,
   * in addition those for the groups above.
   *
   * @var array
   */
  protected $groupIdentifier = [];

  /**
   * @var string|null
   */
  protected $email;

  /**
   * Contact ID.
   *
   * Used to look up the email.
   *
   * @var int|null
   */
  protected ?int $contactID = NULL;

  /**
   * Acoustic recipient ID.
   *
   * @var int|null
   */
  protected ?int $recipientID = NULL;

  public function getRecipientID(): ?int {
    return $this->recipientID;
  }

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
    if (!$this->getEmail() && $this->getContactID()) {
      $this->email = Contact::get(FALSE)
        ->addWhere('id', '=', $this->getContactID())
        ->addSelect('email_primary.email')
        ->execute()->first()['email_primary.email'] ?? NULL;
    }
    $result[] = $omniObject->create([
      'client' => $this->getClient(),
      'mail_provider' => $this->getMailProvider(),
      'database_id' => $this->getDatabaseID(),
      'email' => $this->getEmail(),
      'recipient_id' => $this->getRecipientID(),
      'group_id' => $this->getGroupID(),
      'values' => $this->getValues(),
      'snooze_end_date' => $this->getSnoozeDate(),
      'check_permissions' => $this->getCheckPermissions(),
      'groupIdentifier' => $this->getGroupIdentifier(),
    ]);
  }

  public function getFields(): array {
    return [
      [
        'name' => 'snooze_end_date',
        'required' => FALSE,
        'description' => E::ts('Snooze End Date'),
        'data_type' => 'Datetime',
      ],
      [
        'name' => 'is_opt_out',
        'required' => FALSE,
        'description' => E::ts('Is Opt Out'),
        'data_type' => 'Boolean',
      ],
    ];
  }

  /**
   * @return mixed|null
   */
  public function getSnoozeDate(): mixed {
    $snoozeDate = $this->getValues()['snooze_end_date'] ?? NULL;
    if ($snoozeDate && strtotime($snoozeDate) < time()) {
      $snoozeDate = NULL;
    }
    return $snoozeDate;
  }

}

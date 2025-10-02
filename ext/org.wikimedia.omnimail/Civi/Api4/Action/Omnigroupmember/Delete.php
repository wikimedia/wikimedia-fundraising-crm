<?php
namespace Civi\Api4\Action\Omnigroupmember;

use Civi\Api4\Email;
use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\Generic\Result;
use Civi\Api4\Group;
use GuzzleHttp\Client;
use League\Csv\Exception;
use Omnimail\Omnimail;

/**
 *  Class Check.
 *
 * Provided by the  extension.
 *
 * @method $this setGroupID(int $civicrmGroupID)
 * @method string getGroupID() Get CiviCRM Group ID.
 * @method $this setEmail(string $email) Set email
 * @method $this setContactID(int $contactID)
 * @method $this setGroupIdentifier(int $number)
 * @method $this setMailProvider(string $mailProvider) Generally Silverpop....
 * @method string getMailProvider()
 * @method $this setClient(Client $client) Optional Guzzle client.
 * @method null|Client getClient()
 *
 * @package Civi\Api4
 */
class Delete extends AbstractAction {

  /**
   * CiviCRM group ID to add the imported contact to.
   *
   * @var int
   */
  protected $groupID;

  /**
   * @var string
   */
  protected $mailProvider = 'Silverpop';

  /**
   * @var object|null
   */
  protected $client = NULL;

  /**
   * Email address.
   *
   * @var string|null
   */
  protected ?string $email = NULL;

  /**
   * CiviCRM group ID to add the imported contact to.
   *
   * @var int|null
   */
  protected ?int $contactID = NULL;

  /**
   * Identifier in Acoustic for the group.
   *
   * Note that 'group' is a bit broader than our idea of group ie
   * - the master suppression list ID can be given here
   * - the main database ID can be given here. This is in effect a delete.
   * - a move conventional group ID can be given.
   *
   * @var int|null
   */
  protected ?int $groupIdentifier = NULL;

  protected function getGroupIdentifier(): int {
    if (!isset($this->groupIdentifier)) {
      if ($this->groupID) {
        $this->groupIdentifier = Group::get(FALSE)
         ->addWhere('id', '=', $this->groupID)
          ->addSelect('Group_Metadata.remote_group_identifier')
         ->execute()->first()['Group_Metadata.remote_group_identifier'] ?? FALSE;
      }
    }
    if (!$this->groupIdentifier) {
      throw new \CRM_Core_Exception('Acoustic group identifier or CiviCRM group ID must be provided');
    }
    return $this->groupIdentifier;
  }

  /**
   * @inheritDoc
   *
   * @param \Civi\Api4\Generic\Result $result
   *
   * @throws \CRM_Core_Exception
   * @throws Exception
   */
  public function _run(Result $result): void {
    /* @var \Omnimail\Silverpop\Requests\RemoveRecipient $request */
    $request = Omnimail::create($this->getMailProvider(), \CRM_Omnimail_Helper::getCredentials([
      'client' => $this->getClient(),
      'mail_provider' => $this->getMailProvider(),
    ]))
      ->removeGroupMember([
        'email' => $this->getEmail(),
        'listId' => $this->getGroupIdentifier(),
      ]);
    $request->getResponse();
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

  protected function getEmail(): string {
    if (!isset($this->email)) {
      if ($this->contactID) {
        $this->email = Email::get(FALSE)
          ->addWhere('contact_id', '=', $this->contactID)
          ->addWhere('is_primary', '=', TRUE)
          ->addSelect('email')
          ->execute()->first()['email'] ?? FALSE;
      }
    }
    if (!$this->email) {
      throw new \CRM_Core_Exception('Email or CiviCRM contact ID must be provided');
    }
    return $this->email;
  }

}

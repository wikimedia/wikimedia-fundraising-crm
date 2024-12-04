<?php
namespace Civi\Api4\Action\Omniphone;

use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\Generic\Result;
use Civi\Api4\Omnicontact;
use Civi\Api4\Omniphone;
use Civi\Api4\Phone;
use Civi\Api4\PhoneConsent;
use \CRM_Omnimail_ExtensionUtil as E;
use GuzzleHttp\Client;

/**
 * Find phone records where we need data from Acoustic and update the data.
 *
 * Provided by the  extension.
 *
 * @method $this setDatabaseID(int $databaseID)
 * @method $this setEmail(string|null $email)
 * @method string|null getEmail()
 * @method $this setContactID(int $contactID)
 * @method int getContactID()
 * @method $this setRecipientID(int $contactID)
 * @method int getRecipientID()
 * @method $this setLimit(int $limit)
 * @method int getLimit()
 * @method $this setMailProvider(string $mailProvider) Generally Silverpop....
 * @method string getMailProvider()
 * @method $this setClient(Client$client) Generally Silverpop....
 * @method null|Client getClient()
 *
 * @package Civi\Api4
 */
class BatchUpdate extends AbstractAction {

  /**
   * @var object
   */
  protected $client;

  /**
   * @var int
   */
  protected $limit = 10;

  /**
   * @var int
   */
  protected $contactID;

  /**
   * @var string
   */
  protected $email;

  /**
   * @var int
   */
  protected $recipientID;

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
    $phoneGet = Phone::get(FALSE)
      ->addWhere('phone', '=', \CRM_Omnimail_Omnicontact::DUMMY_PHONE)
      ->addWhere('phone_data.recipient_id', 'IS NOT NULL')
      ->addSelect('phone_data.*')
      ->setLimit($this->limit);
    if ($this->getRecipientID()) {
      $phoneGet->addWhere('recipient_id', '=', $this->getRecipientID());
    }
    if ($this->getContactID()) {
      $phoneGet->addWhere('contact_id', '=', $this->getContactID());
    }
    if ($this->getEmail()) {
      $phoneGet->addWhere('contact_id.email_primary', '=', $this->getEmail());
    }
    $phones = $phoneGet->execute();
    foreach ($phones as $phone) {
      $record = Omniphone::update($this->getCheckPermissions())
        ->setMailProvider($this->getMailProvider())
        ->setClient($this->getClient())
        ->setDatabaseID($this->getDatabaseID())
        ->setRecipientID($phone['phone_data.recipient_id'])
        ->setPhoneID($phone['id'])
        ->execute()->first();
      $result[] = $record;
    }
  }

  public function fields(): array {
    return [];
  }

}

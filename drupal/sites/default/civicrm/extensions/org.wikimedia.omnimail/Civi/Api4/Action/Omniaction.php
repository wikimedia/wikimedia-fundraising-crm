<?php
namespace Civi\Api4\Action;

use Civi\Api4\Generic\AbstractAction;
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
 * @method $this setLimit(int $limit)
 * @method int getLimit()
 * @method $this setOffset(int $offset)
 * @method int getOffset()
 * @method $this setStart(string $start)
 * @method string getStart()
 * @method $this setEnd(string $end)
 * @method string getEnd()
 * @method $this setRecipientID(int $contactID)
 * @method int getRecipientID()
 * @method $this setMailProvider(string $mailProvider) Generally Silverpop....
 * @method string getMailProvider()
 * @method $this setClient(Client$client) Generally Silverpop....
 * @method null|Client getClient()
 *
 * @package Civi\Api4
 */
abstract class Omniaction extends AbstractAction {

  /**
   * @var object
   */
  protected $client;

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
  protected int $limit = 0;

  /**
   * @var int
   */
  protected int $offset = 0;

  /**
   * @var string
   */
  protected string $start = '';

  /**
   * @var string
   */
  protected string $end = '';

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
   */
  public function getDatabaseID(): int {
    if (!$this->databaseID) {
      $this->databaseID = \Civi::settings()->get('omnimail_credentials')[$this->getMailProvider()]['database_id'][0];
    }
    return $this->databaseID;
  }

  public function fields(): array {
    return [
      [
        'name' => 'DatabaseID',
        'data_type' => 'Array',
      ],
      [
        'name' => 'mail_provider',
        'data_type' => 'String',
        'default' => 'Silverpop',
      ],
      ['name' => 'start', 'type' => 'datetime'],
      ['name' => 'end', 'type' => 'datetime'],
    ];
  }

}

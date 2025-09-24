<?php

namespace Civi\Api4\Action\Omnicontact;

use Civi\Api4\Email;
use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\Generic\Result;
use Civi\Api4\Omnicontact;
use GuzzleHttp\Client;

/**
 * Audit whether snoozed contacts are correctly snoozed remotely.
 *
 * @see https://wikitech.wikimedia.org/wiki/Fundraising/Data_and_Integrated_Processes/Acoustic_Integration/Snooze
 *
 * @method $this setDatabaseID(int $databaseID)
 * @method $this setMailProvider(string $mailProvider) Generally Silverpop....
 * @method string getMailProvider()
 * @method $this setToDateTime(string $dateTimeString)
 * @method $this setFromDateTime(string $dateTimeString)
 * @method $this setClient(Client $client) Generally Silverpop....
 * @method null|Client getClient()
 */
class VerifySnooze extends AbstractAction {

  /**
   * @var object
   */
  protected $client;

  /**
   * @var int
   */
  protected $databaseID;

  /**
   * @var string
   */
  protected $mailProvider = 'Silverpop';

  /**
   * Audit from date time.
   *
   * @var string
   */
  protected $fromDateTime = '1 week ago';

  /**
   * Audit to date time.
   *
   * @var string
   */
  protected $toDateTime = 'now';

  public function getToDateTime() {
    return date('Y-m-d H:i:s', strtotime($this->toDateTime));
  }

  public function getFromDateTime() {
    return date('Y-m-d H:i:s', strtotime($this->fromDateTime));
  }

  /**
   * @throws \CRM_Core_Exception
   */
  public function _run(Result $result): void {
    $snoozedEmails = \CRM_Core_DAO::executeQuery(
      'SELECT entity_id FROM log_civicrm_value_email
         WHERE log_date BETWEEN "' . $this->getFromDateTime() . '" AND "' . $this->getToDateTime() . '"'

    )->fetchAll();
    foreach ($snoozedEmails as $email) {
      // do something
      $snoozedEmail = Email::get(FALSE)
        ->addSelect('email', 'id', 'contact_id', 'email_settings.snooze_date')
        ->addWhere('id', '=', $email['entity_id'])
        ->execute()->first();
      if (!$snoozedEmail || empty($snoozedEmail['email_settings.snooze_date'])
        // If the snooze is less than a day into the future then leave it - it's on it's way out
        // & who wants to calculate timezones!!
        || strtotime($snoozedEmail['email_settings.snooze_date']) < strtotime('+ 1 day')
      ) {
        // If the 'real' email (as opposed to the log email) does not exist
        // or does not have a future snooze date then skip.
        continue;
      }
      try {
        // if (date($snoozedEmails['email_settings']['snooze_date']) < ) {}
        $remoteRecord = Omnicontact::get(FALSE)
          ->setClient($this->getClient())
          ->setEmail($snoozedEmail['email'])
          ->execute()->first();
        if (!$remoteRecord || empty($remoteRecord['snooze_end_date'])
          || !$remoteRecord['snooze_fields_match']
          || strtotime($remoteRecord['snooze_end_date']) < strtotime($snoozedEmail['email_settings.snooze_date'])
        ) {
          $result[] = $snoozedEmail + $remoteRecord;
        }
      }
      catch (\Exception $e) {
        \Civi::log('wmf')->info('unable to retrieve from Acoustic: ' . $snoozedEmail['email']) . $e->getMessage();
      }
    }
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

}

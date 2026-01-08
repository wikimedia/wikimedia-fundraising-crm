<?php

namespace Civi\Api4\Action\Omnicontact;

use Civi\Api4\Email;
use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\Generic\Result;
use Civi\Api4\Omnicontact;
use Civi\Api4\WMFContact;
use GuzzleHttp\Client;

/**
 * Audit whether snoozed contacts are correctly snoozed remotely.
 *
 * @see https://wikitech.wikimedia.org/wiki/Fundraising/Data_and_Integrated_Processes/Acoustic_Integration/Snooze
 *
 * @method $this setDatabaseID(int $databaseID)
 * @method $this setMailProvider(string $mailProvider) Generally Silverpop....
 * @method string getMailProvider()
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
   * @throws \CRM_Core_Exception
   */
  public function _run(Result $result): void {
    // If the snooze is less than a day into the future then leave it - it's on its way out
    // & who wants to calculate timezones!!
    $snoozedEmails = Email::get(FALSE)
      ->addSelect('email', 'id', 'contact_id', 'email_settings.snooze_date')
      ->addWhere('email_settings.snooze_date', '>', gmdate('Y-m-d H:i:s', strtotime('+1 day')))
      ->addChain('emailable', WMFContact::bulkEmailable()->setEmail('$email')->setCheckSnooze(FALSE))
      ->execute();
    foreach ($snoozedEmails as $snoozedEmail) {
      // Don't try to snooze a contact who should already be opted out.
      if ($snoozedEmail['emailable'][0] === false) {
        continue;
      }
      try {
        $remoteRecord = Omnicontact::get(FALSE)
          ->setClient($this->getClient())
          ->setEmail($snoozedEmail['email'])
          ->execute()->first();
        // It would be nice to also check is_opt_out from Acoustic here, but the API response does not seem to be correct.
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

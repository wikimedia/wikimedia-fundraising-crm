<?php
namespace Civi\Api4\Action\Omniphone;

use Civi\Api4\Activity;
use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\Generic\Result;
use Civi\Api4\Omnicontact;
use Civi\Api4\Phone;
use Civi\Api4\PhoneConsent;

/**
 * Find phone records where we need data from Acoustic and update the data.
 *
 * Provided by the  extension.
 *
 * @method $this setDatabaseID(int $databaseID)
 * @method $this setPhoneID(int $phoneID)
 * @method int getPhoneID()
 * @method $this setRecipientID(int $recipientID)
 * @method int getRecipientID()
 * @method $this setContactID(int $contactID)
 * @method $this setLimit(int $limit)
 * @method int getLimit()
 * @method $this setMailProvider(string $mailProvider) Generally Silverpop....
 * @method string getMailProvider()
 * @method $this setClient(\GuzzleHttp\Client$client) Generally Silverpop....
 * @method null|\GuzzleHttp\Client getClient()
 *
 * @package Civi\Api4
 */
class Update extends AbstractAction {

  /**
   * @var object
   */
  protected $client;

  /**
   * @var int
   */
  protected $recipientID;

  /**
   * @var int
   */
  protected $phoneID;

  /**
   * @var int
   */
  protected $contactID;

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

  /**
   * Get the contact ID, loading it from the phone if not provided.
   *
   * @return int
   */
  public function getContactID(): int {
    if (!$this->contactID) {
      $this->contactID = Phone::get(FALSE)
        ->addWhere('id', '=', $this->getPhoneID())
        ->addSelect('contact_id')
        ->execute()->first()['contact_id'];
    }
    return $this->contactID;
  }

  /**
   * @inheritDoc
   *
   * @param \Civi\Api4\Generic\Result $result
   *
   * @throws \CRM_Core_Exception
   */
  public function _run(Result $result): void {
    $details = Omnicontact::get(FALSE)
      ->setMailProvider($this->getMailProvider())
      ->setClient($this->getClient())
      ->setDatabaseID($this->getDatabaseID())
      ->setCheckPermissions($this->getCheckPermissions())
      ->setRecipientID($this->getRecipientID())
      ->execute()->first();

    // This if is cos it feels like we should check 'something'
    if (!empty($details['sms_consent_status'])) {
      // So far we can assume the number is from US & leads with a 1.
      // ie. single digit - is US the only single digit?
      Phone::update(FALSE)
        ->addWhere('id', '=', $this->getPhoneID())
        ->setValues([
          'phone' => substr($details['mobile_phone'], 1),
          'phone_data.update_date' => $details['sms_consent_datetime'],
          'phone_data.phone_source' => 'Acoustic',
        ])
        ->execute();

      $record = [
        'country_code' => substr($details['mobile_phone'], 0, 1),
        'phone_number' => substr($details['mobile_phone'], 1),
        'master_recipient_id' => $this->getRecipientID(),
        'consent_date' => $details['sms_consent_datetime'],
        'consent_source' => $details['sms_consent_source'],
        'opted_in' => $details['sms_consent_status'] === 'OPTED-IN',
      ];
      PhoneConsent::save(FALSE)
        ->setMatch(['phone_number'])
        ->addRecord($record)
        ->execute();

      Activity::create(FALSE)
        ->setValues([
          'activity_type_id:name' => $details['sms_consent_status'] === 'OPTED-IN' ? 'sms_consent_given' : 'sms_consent_revoked',
          'activity_date_time' => $details['sms_consent_datetime'],
          'status_id:name' => 'Completed',
          'source_contact_id' => $this->getContactID(),
          'subject' => $details['sms_consent_status'] === 'OPTED-IN' ? 'SMS consent given for ' . $details['mobile_phone'] : 'SMS consent revoked for ' . $details['mobile_phone'],
          'details' => 'Acoustic opt in information : ' . $details['sms_consent_source'],
          // These fields are kinda legacy but since they exist I guess we stick data in them.
          'phone_number' => $details['mobile_phone'],
          'phone_id' => $this->getPhoneID(),
        ])
        ->execute();

      $result[] = $record;
    }
  }

  public function fields(): array {
    return [];
  }

}

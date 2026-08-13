<?php
namespace Civi\Api4\Action\Omnigroupmember;

use Civi\Api4\Action\Omniaction;
use Civi\Api4\Activity;
use Civi\Api4\Email;
use Civi\Api4\Generic\Result;
use Civi\Api4\GroupContact;
use Civi\Api4\Omnicontact;
use Civi\Api4\Phone;
use Civi\Api4\PhoneConsent;
use GuzzleHttp\Client;
use League\Csv\Exception;
use League\Csv\UnavailableStream;
use Omnimail\Silverpop\Responses\Contact;

/**
 *  Class Check.
 *
 * Provided by the  extension.
 *
 * @method $this setGroupID(string $listName)
 * @method string getGroupID() Get CiviCRM Group ID.
 * @method $this setDatabaseID(int $databaseID)
 * @method $this setIsPublic(bool $isListPublic)
 * @method bool getIsPublic()
 * @method int getThrottleSeconds()
 * @method $this setThrottleSeconds(int $seconds)
 * @method int getThrottleNumber()
 * @method $this setThrottleNumber(int $number)
 * @method int getLimit()
 * @method $this setLimit(int $limit)
 * @method int getTimeout()
 * @method $this setTimeout(int $timeOut)
 * @method int getGroupIdentifier() Get Acoustic Group Identifier.
 * @method $this setGroupIdentifier(int $number)
 * @method $this setJobIdentifier(?string $identifier)
 * @method int getIsSuppressionList() Get whether this is a suppression list check.
 * @method $this setIsSuppressionList(bool $isSuppression)
 * @method $this setMailProvider(string $mailProvider) Generally Silverpop....
 * @method string getMailProvider()
 * @method $this setIsConsentOptOutGroup(bool $isConsentOptOutGroup)
 * @method $this setIsConsentOptInGroup(bool $isConsentOptInGroup)
 * @method $this setClient(Client$client) Generally Silverpop....
 * @method null|Client getClient()
 *
 * @package Civi\Api4
 */
class Load extends Omniaction {

  /**
   * CiviCRM group ID to add the imported contact to.
   *
   * @var int
   */
  protected $groupID;

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
   * Is the list to be visible to other acoustic users.
   *
   * @var bool
   */
  protected bool $isPublic = TRUE;

  /**
   * Max Number of rows to process.
   *
   * @var int
   */
  protected int $limit = 10000;

  /**
   * Throttle after the number has been reached in this number of seconds.
   *
   * If the throttle limit is passed before this number of seconds is reached php will sleep until it hits it.
   *
   * @var int
   */
  protected int $throttleSeconds = 60;

  protected int $timeout = 10;

  /**
   * Identifier in Acoustic for the group.
   *
   * @required
   *
   * @var int
   */
  protected $groupIdentifier;

  /**
   * Is this a suppression list request.
   *
   * The suppression list requests cannot get all the columns that are in
   * the main database and need to include opted in contacts.
   *
   * @default false
   *
   * @var bool
   */
  protected $isSuppressionList;

  /**
   * Number of inserts to throttle after.
   *
   * @var int
   */
  protected int $throttleNumber = 5000;

  /**
   * Identifier for tracking job progress.
   *
   * @var string|null
   */
  protected ?string $jobIdentifier = NULL;

  /**
   * Is this a group of recipients who have opted out of SMS consents.
   *
   * @var bool
   */
  protected bool $isConsentOptOutGroup = FALSE;

  /**
   * Is this a group of recipients who have opted into SMS consents
   *
   * @var bool
   */
  protected bool $isConsentOptInGroup = FALSE;

  public function getJobIdentifier(): string {
    return $this->jobIdentifier ?: ($this->getIsSuppressionList() ? 'suppress_' : '') . $this->getGroupIdentifier();
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
    $throttleSeconds = $this->getThrottleSeconds();
    $throttleStagePoint = strtotime('+ ' . (int) $throttleSeconds . ' seconds');
    $throttleCount = $this->getThrottleNumber();
    $rowsLeftBeforeThrottle = $this->getThrottleNumber();
    if ($this->isConsentOptInGroup && $this->isConsentOptOutGroup) {
      throw new \CRM_Core_Exception('opt in and opt out are mutually exclusive');
    }

    $params = [
      'mail_provider' => $this->getMailProvider(),
      'group_identifier' => $this->getGroupIdentifier(),
      'is_suppression_list' => $this->getIsSuppressionList(),
      'limit' => $this->getLimit(),
      'client' => $this->getClient(),
      'database_id' => $this->getDatabaseID(),
      'job_identifier' => $this->getJobIdentifier(),
      'offset' => $this->getOffset(),
      'timeout' => $this->getTimeout(),
      'start_date' => $this->start ?: NULL,
      'is_include_opt_out' => $this->getIsSuppressionList() || $this->isConsentOptOutGroup,
    ];

    $job = new \CRM_Omnimail_Omnigroupmembers($params);
    $jobSettings = $job->getJobSettings();
    try {
      $contacts = $job->getResult($params);
    }
    catch (\CRM_Omnimail_IncompleteDownloadException $e) {
      $job->saveJobSetting([
        'retrieval_parameters' => $e->getRetrievalParameters(),
        'progress_end_timestamp' => $e->getEndTimestamp(),
        'offset' => 0,
      ]);
      return;
    }
    catch (UnavailableStream $e) {
      // The csv could not be loaded - forget about it and request it again.
      // The file is deleted from the remote once downloaded, so if our copy is
      // gone the stored retrieval parameters are dead & would wedge the job
      // forever. last_timestamp is left alone so we restart from known success.
      $job->saveJobSetting([
        'progress_end_timestamp' => 'null',
        'offset' => 'null',
        'retrieval_parameters' => 'null',
      ], 'omnigroupmember_file_failed');
      throw new \CRM_Core_Exception('file error - try again');
    }

    $offset = $job->getOffset();
    $limit = $params['limit'] ?? NULL;
    $count = 0;

    foreach ($contacts as $row) {
      $contact = new Contact($row);
      if ($count === $limit) {
        $job->saveJobSetting(array(
          'last_timestamp' => $jobSettings['last_timestamp'] ?? NULL,
          'retrieval_parameters' => $job->getRetrievalParameters(),
          'progress_end_timestamp' => $job->endTimeStamp,
          'offset' => $offset + $count,
        ));
        // Do this here - ie. before processing a new row rather than at the end of the last row
        // to avoid thinking a job is incomplete if the limit co-incides with available rows.
        return;
      }
      $groupMember = $job->formatRow($contact);
      if (!empty($groupMember['email'])) {
        $emails = Email::get(FALSE)
          ->addWhere('email', '=', $groupMember['email'])
          ->execute();
        if (!$this->getIsSuppressionList() && count($emails) === 0) {
          // If there is already a contact with this email we will skip for now.
          // It might that we want to create duplicates, update contacts or do other actions later
          // but let's re-assess when we see that happening. Spot checks only found emails not
          // otherwise in the DB.
          $source = (empty($params['mail_provider']) ? ts('Mail Provider') : $params['mail_provider']) . ' ' . (!empty($groupMember['source']) ? $groupMember['source'] : $groupMember['opt_in_source']);
          $source .= ' ' . $groupMember['created_date'];

          $contactParams = [
            'contact_type' => 'Individual',
            'email' => $groupMember['email'],
            'is_opt_out' => $groupMember['is_opt_out'],
            'source' => $source,
            'preferred_language' => $groupMember['preferred_language'],
            'email_primary.email' => $groupMember['email'],
          ];

          $contactCreateCall = \Civi\Api4\Contact::create(FALSE)
            ->setValues($contactParams);

          if (!empty($groupMember['country']) && $this->isCountryValid($groupMember['country'])) {
            $contactCreateCall->addValue('address_primary.country_id:abbr', $groupMember['country']);
          }

          if ($this->getGroupID()) {
            $contactCreateCall->addChain(
              'groupContact',
              GroupContact::create(FALSE)->setValues([
                'contact_id' => '$id',
                'group_id' => $this->getGroupID(),
              ])
            );
          }
          $createdContact = $contactCreateCall->execute()->first();
          $result[$createdContact['id']] = $createdContact;
        }
        elseif ($this->getIsSuppressionList() && count($emails) > 0) {
          foreach ($emails as $email) {
            if ($email['is_primary']) {
              $existingRecord = GroupContact::get(FALSE)
                ->addWhere('contact_id', '=', $email['contact_id'])
                ->addWhere('group_id', '=', $this->getGroupID())
                ->execute()->first();
              if (!$existingRecord) {
                GroupContact::save(FALSE)->addRecord([
                  'contact_id' => $email['contact_id'],
                  'group_id' => $this->getGroupID(),
                ])->execute();
              }
              $result[$email['contact_id']] = $email;
            }
          }
        }
      }
      if (!empty($groupMember['phone'])) {
        // This is an SMS contact.
        ['country_code' => $countryCode, 'phone_number' => $phone] = \Civi\WMFHelper\Phone::splitUsNumber($groupMember['phone']);
        $existingConsent = PhoneConsent::get(FALSE)
          ->addWhere('phone_number', '=', $phone)
          ->addWhere('country_code', '=', $countryCode)
          ->execute()->first();

        if (!$existingConsent
          || ($this->isConsentOptOutGroup && $existingConsent['opted_in'])
          || ($this->isConsentOptInGroup && !$existingConsent['opted_in'])
        ) {
          // Consent needs updating if there is no existing consent or the existing
          // consent differs to the remote. We only check the remote if it seems
          // likely to be different based on isConsentOptOutGroup/isConsentOptInGroup
          // This is to save us looking up every single one - the group criteria
          // at the Acoustic end is set to opt in our out.
          $remoteContact = Omnicontact::get(FALSE)
            ->setRecipientID($groupMember['recipient_id'])
            ->execute()->first();

          $optedIn = $remoteContact['sms_consent_status'] === 'OPTED-IN';
          $idValue = $existingConsent ? ['id' => $existingConsent['id']] : [];
          // Only set master_recipient_id on create. If we overwrite a null or existing
          // value we will end up pushing up the new master_recipient_id as is_orphan,
          // when it would be a non-orphan full contact.
          $recipientValue = $existingConsent ? [] : ['master_recipient_id' => $groupMember['recipient_id']];
          PhoneConsent::save(FALSE)
            ->addRecord($idValue + $recipientValue + [
              'country_code' => $countryCode,
              'phone_number' => $phone,
              // Since these contacts are ONLY opted in to SMS we assume these values
              // apply to SMS.
              'consent_date' => $remoteContact['sms_consent_datetime'],
              'consent_source' => $remoteContact['sms_consent_source'],
              'opted_in' => $optedIn,
            ])
            ->execute();

          if (!$existingConsent || ($existingConsent['opted_in'] !== $optedIn)) {
            $this->createConsentActivity($phone, $optedIn, $remoteContact);
          }
        }
      }
      $count++;
      // Every row seems extreme but perhaps not in this performance monitoring phase.
      $job->saveJobSetting(array_merge($jobSettings, ['offset' => $offset + $count]));

      $rowsLeftBeforeThrottle--;
      if ($throttleStagePoint && (strtotime('now') >= $throttleStagePoint)) {
        $throttleStagePoint = strtotime('+ ' . (int) $throttleSeconds . 'seconds');
        $rowsLeftBeforeThrottle = $throttleCount;
      }

      if ($throttleSeconds && $rowsLeftBeforeThrottle <= 0) {
        sleep(ceil($throttleStagePoint - strtotime('now')));
      }
    }

    $job->saveJobSetting([
      'last_timestamp' => $job->endTimeStamp,
      'progress_end_timestamp' => 'null',
      'retrieval_parameters' => 'null',
      'offset' => 'null',
    ]);
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
    return parent::fields() + [
      [
        'name' => 'isConsentOptInGroup',
        'label' => 'Is this a group of recipients who have opted into SMS?',
        'data_type' => 'Boolean',
        'default' => FALSE,
      ],
      [
        'name' => 'isConsentOptOutGroup',
        'label' => 'Is this a group of recipients who have opted out of SMS?',
        'data_type' => 'Boolean',
        'default' => FALSE,
      ],
    ];
  }

  /**
   * Record an SMS consent change on the CiviCRM contacts with the number.
   *
   * If there is no contact, we don't create an activity.
   *
   * @throws \CRM_Core_Exception
   */
  private function createConsentActivity(string $phone, bool $optedIn, array $remoteContact): void {
    $phoneRecords = Phone::get(FALSE)
      ->addWhere('phone_numeric', '=', $phone)
      ->addSelect('id', 'contact_id')
      ->execute()->indexBy('contact_id');
    foreach ($phoneRecords as $phoneRecord) {
      Activity::create(FALSE)
        ->setValues([
          'activity_type_id:name' => $optedIn ? 'sms_consent_given' : 'sms_consent_revoked',
          'activity_date_time' => $remoteContact['sms_consent_datetime'],
          'status_id:name' => 'Completed',
          'source_contact_id' => $phoneRecord['contact_id'],
          'subject' => ($optedIn ? 'SMS consent given for ' : 'SMS consent revoked for ') . $phone,
          'details' => 'Acoustic consent source: ' . $remoteContact['sms_consent_source'],
          'phone_number' => $phone,
          'phone_id' => $phoneRecord['id'],
          'SMS_consent.Consent_source:name' => 'Acoustic',
        ])
        ->execute();
    }
  }

  /**
   * Check if the country is valid.
   *
   * @param string $country
   *
   * @return bool
   */
  private function isCountryValid($country): bool {
    static $countries = NULL;
    if (!$countries) {
      $countries = \CRM_Core_PseudoConstant::countryIsoCode();
    }
    return array_search($country, $countries) ? $country : FALSE;
  }

}

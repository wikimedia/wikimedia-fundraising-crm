<?php
namespace Civi\Api4\Action\Omnigroupmember;

use Civi\Api4\Email;
use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\Generic\Result;
use Civi\Api4\GroupContact;
use Civi\Api4\PhoneConsent;
use GuzzleHttp\Client;
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
 * @method int|null getOffset()
 * @method $this setOffset(int|null $offset)
 * @method int getGroupIdentifier() Get Acoustic Group Identifier.
 * @method $this setGroupIdentifier(int $number)
 * @method $this setJobIdentifier(?string $identifier)
 * @method int getIsSuppressionList() Get whether this is a suppression list check.
 * @method $this setIsSuppressionList(bool $isSuppression)
 * @method $this setMailProvider(string $mailProvider) Generally Silverpop....
 * @method string getMailProvider()
 * @method $this setClient(Client$client) Generally Silverpop....
 * @method null|Client getClient()
 *
 * @package Civi\Api4
 */
class Load extends AbstractAction {

  /**
   * @var object
   */
  protected $client;

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
  protected $isPublic = TRUE;

  /**
   * Max Number of rows to process.
   *
   * @var int
   */
  protected $limit = 10000;

  /**
   * Max Number of rows to process.
   *
   * @var int|null
   */
  protected $offset = NULL;

  /**
   * Throttle after the number has been reached in this number of seconds.
   *
   * If the throttle limit is passed before this number of seconds is reached php will sleep until it hits it.
   *
   * @var int
   */
  protected int $throttleSeconds = 60;

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

  public function getJobIdentifier(): string {
    return $this->jobIdentifier ?: ($this->getIsSuppressionList() ? 'suppress_' : '') . $this->getGroupIdentifier();
  }

  /**
   * @inheritDoc
   *
   * @param \Civi\Api4\Generic\Result $result
   *
   * @throws \CRM_Core_Exception
   */
  public function _run(Result $result): void {
    $throttleSeconds = $this->getThrottleSeconds();
    $throttleStagePoint = strtotime('+ ' . (int) $throttleSeconds . ' seconds');
    $throttleCount = $this->getThrottleNumber();
    $rowsLeftBeforeThrottle = $this->getThrottleNumber();

    $params = [
      'mail_provider' => $this->getMailProvider(),
      'group_identifier' => $this->getGroupIdentifier(),
      'is_suppression_list' => $this->getIsSuppressionList(),
      'limit' => $this->getLimit(),
      'client' => $this->getClient(),
      'database_id' => $this->getDatabaseID(),
      'job_identifier' => $this->getJobIdentifier(),
      'offset' => $this->getOffset(),
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

    $offset = $job->getOffset();
    $limit = $params['limit'] ?? NULL;
    $count = 0;

    foreach ($contacts as $row) {
      $contact = new Contact($row);
      if ($count === $limit) {
        $job->saveJobSetting(array(
          'last_timestamp' => $jobSettings['last_timestamp'],
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
          $contact = $contactCreateCall->execute()->first();
          $result[$contact['id']] = $contact;
        }
        elseif ($this->getIsSuppressionList() && count($emails) > 0) {
          foreach ($emails as $email) {
            if ($email['is_primary']) {
              GroupContact::create(FALSE)->setValues([
                'contact_id' => $email['contact_id'],
                'group_id' => $this->getGroupID(),
              ])->execute();
              $result[$email['contact_id']] = $email;
            }
          }
        }
      }
      if (empty($groupMember['email']) && !empty($groupMember['phone'])) {
        // This is an SMS only contact.
        if (str_starts_with($groupMember['phone'], 1)) {
          // 1 = United States = Weird
          $countryCode = substr($groupMember['phone'], 0, 1);
          $phone = substr($groupMember['phone'], 1);
        }
        else {
          // We only have United States at the moment but if we ever have others
          // they are 2 digit codes.
          $countryCode = substr($groupMember['phone'], 0, 2);
          $phone = substr($groupMember['phone'], 2);
        }
        $existingConsent = PhoneConsent::get(FALSE)
          ->addWhere('phone_number', '=', $phone)
          ->addWhere('country_code', '=', $countryCode)
          ->execute()->first();
        if (!$existingConsent) {
          PhoneConsent::create(FALSE)
            ->setValues([
              'country_code' => $countryCode,
              'phone_number' => $phone,
              'master_recipient_id' => $groupMember['recipient_id'],
              // Since these contacts are ONLY opted in to SMS we assume these values
              // apply to SMS.
              'consent_date' => $groupMember['opt_in_date'],
              'consent_source' => $groupMember['opt_in_source'],
              'opted_in' => $groupMember['is_opt_in'],
            ])->execute();
        }
        elseif ($existingConsent['opted_in'] !== $groupMember['is_opt_in']) {
          $consent = PhoneConsent::update(FALSE)
            ->addValue('opted_in', $groupMember['is_opt_in'])
            ->addWhere('id', '=', $existingConsent['id']);
          if ($groupMember['is_opt_in']) {
            // We don't really expect phones that are opted out to
            // be re-opted in through this.
            // Perhaps it's better to
            // make sure we see any, given their unexpected nature?
            \Civi::log('wmf')->alert('opt out reversed for recipient {recipient_id} in Acoustic. This is unexpected and we should understand this flow', [
              'recipient_id' => $groupMember['recipient_id'],
            ]);
            $consent->addValue('consent_date', $groupMember['opt_in_date'])
              ->addValue('consent_source', $groupMember['opt_in_source']);
          }
          $consent->execute();
        }
      }
      $count++;
      // Every row seems extreme but perhaps not in this performance monitoring phase.
      $job->saveJobSetting(array_merge($jobSettings, array('offset' => $offset + $count)));

      $rowsLeftBeforeThrottle--;
      if ($throttleStagePoint && (strtotime('now') > $throttleStagePoint)) {
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
    return [
      [
        'name' => 'throttle_seconds',
        'required' => TRUE,
        'description' => ts('Contribution ID'),
        'data_type' => 'Integer',
        'fk_entity' => 'Contribution',
        'input_type' => 'EntityRef',
      ],
    ];
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

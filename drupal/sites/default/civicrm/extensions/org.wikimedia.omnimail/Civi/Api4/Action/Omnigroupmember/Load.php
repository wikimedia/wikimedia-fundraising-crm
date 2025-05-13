<?php
namespace Civi\Api4\Action\Omnigroupmember;

use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\Generic\Result;
use Civi\Api4\GroupContact;
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
 * @method string|null getJobIdentifier() Get progress tracking Identifier.
 * @method $this setJobIdentifier(?string $identifier)
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
   * @required
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
   * Number of inserts to throttle after.
   *
   * @var int
   */
  protected int $throttleNumber = 5000;

  /**
   * Optional identifier for tracking job progress.
   *
   * @var string|null
   */
  protected $jobIdentifier;

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
      'is_opt_in_only' => TRUE,
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
      if (!empty($groupMember['email']) && !civicrm_api3('email', 'getcount', ['email' => $groupMember['email']])) {
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

<?php
namespace Civi\Api4\Action\PhoneConsent;

use Civi\Api4\Action\OmnimailJobProgress\CheckStatus;
use Civi\Api4\Generic\AbstractUpdateAction;
use Civi\Api4\Omnicontact;
use League\Csv\Writer;

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
 * @method $this setIsTest(bool $isTest);
 * @method bool getIsTest();
 * @method $this setClient(\GuzzleHttp\Client$client) Generally Silverpop....
 * @method null|\GuzzleHttp\Client getClient()
 *
 * @package Civi\Api4
 */
class RemoteUpdate extends AbstractUpdateAction {

  /**
   * @var object
   */
  protected $client;

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

  protected $values = [];

  protected $isTest = FALSE;

  /**
   * Update the Acoustic records.
   *
   * We need to run 3 jobs here - Acoustic will not let us combine them & 1 must
   * run before 2.
   * 1) add the mobile_phone to the relevant email
   * 2) re-consent the mobile_phone
   * 3) update the orphan record as being an orphan.
   *
   * @param array $items
   * @return array
   * @throws \CRM_Core_Exception
   */
  protected function updateRecords(array $items): array {
    $folders = \Civi::settings()->get('omnimail_allowed_upload_folders');
    $path = reset($folders) . '/' . 'remote-upload' . date('YmdHis');
    $addEmailCsv = Writer::from($path . '-add-email.csv', 'w+');
    $addConsentCsv = Writer::from($path . '-add-consent.csv', 'w+');
    $orphanCsv = Writer::from($path . '-set-orphan.csv', 'w+');

    // Write header rows
    $addEmailCsv->insertOne(['email', 'mobile_phone']);
    $addConsentCsv->insertOne([
      'mobile_phone',
      'CONSENT_DATE',
      'CONSENT_STATUS_CODE',
      'CONSENT_SOURCE',
    ]);
    $orphanCsv->insertOne(['RECIPIENT_ID', 'is_orphan']);

    foreach ($items as $item) {
      $addEmailCsv->insertOne([
        'Email' => $item['phone.contact_id.email_primary.email'],
        'mobile_phone' => $item['country_code'] . $item['phone_number'],
      ]);
      $addConsentCsv->insertOne([
        'mobile_phone' => $item['country_code'] . $item['phone_number'],
        'CONSENT_DATE' => date('m/d/Y H:i:s', strtotime($item['consent_date'])),
        'CONSENT_STATUS_CODE' => $item['opted_in'] ? 'OPTED-IN' : 'OPTED-OUT',
        'CONSENT_SOURCE' => $item['consent_source'],
      ]);
      $orphanCsv->insertOne([$item['master_recipient_id'], 'Yes']);
    }
    \Civi::log('omnimail')->info('output to file {path}', ['path' => $path]);
    $result = Omnicontact::upload(FALSE)
      ->setClient($this->getClient())
      ->setIsAlreadyUploaded($this->getIsTest())
      ->setCsvFile($addEmailCsv->getPathname())->execute();
    $omniObject = new \CRM_Omnimail_OmnimailJobProgress([
      'mail_provider' => $this->getMailProvider(),
    ]);

    for ($i = 0; $i < \Civi::settings()->get('omnimail_job_retry_number'); $i++) {
      $activeJob = $omniObject->checkStatus([
        'job_id' => $result->first()['job_id'],
        'client' => $this->getClient(),
        'mail_provider' => $this->getMailProvider(),
      ]);
      if ($activeJob['is_complete']) {
        // The first one needs to happen first - but these 2 are OK async.
        Omnicontact::upload(FALSE)
          ->setClient($this->getClient())
          ->setIsAlreadyUploaded($this->getIsTest())
          ->setCsvFile($addConsentCsv->getPathname())->execute();
        Omnicontact::upload(FALSE)
          ->setClient($this->getClient())
          ->setIsAlreadyUploaded($this->getIsTest())
          ->setCsvFile($orphanCsv->getPathname())->execute();
        return $items;

      }
      else {
        sleep(\Civi::settings()->get('omnimail_job_retry_interval'));
      }
    }
    throw new \CRM_Core_Exception('The first upload took too long. We do not know if we have to work around the fact the first upload needs to complete before the second....');
  }

  /**
   * Get an API action object which resolves the list of records for this batch.
   *
   * This is similar to `getBatchRecords()`, but you may further refine the
   * API call (e.g. selecting different fields or data-pages) before executing.
   *
   * @return \Civi\Api4\Generic\AbstractGetAction
   */
  protected function getBatchAction() {
    $params = [
      'checkPermissions' => $this->checkPermissions,
      'where' => $this->where,
      'orderBy' => $this->orderBy,
      'limit' => $this->limit,
      'offset' => $this->offset,
    ];
    if (empty($this->reload)) {
      $params['select'] = $this->getSelect();
    }
    $params['select'][] = '*';
    $params['select'][] = 'phone.*';
    $params['select'][] = 'phone.contact_id.email_primary.email';
    $params['join'] = [
      ['Phone AS phone', 'INNER', ['phone_number', '=', 'phone.phone_numeric']],
    ];
    return \Civi\API\Request::create($this->getEntityName(), 'get', ['version' => 4] + $params);
  }

  /**
   * We are just sneaking in a 'get all' where because the parent requires a where.
   *
   * @param array $record
   * @param string|null $entityName
   * @param string|null $actionName
   * @throws \CRM_Core_Exception
   */
  protected function formatWriteValues(&$record, ?string $entityName = null, ?string $actionName = null) {
    $this->where = [['id', '>', 0]];
    parent::formatWriteValues($record, $entityName, $actionName);
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

  public function fields(): array {
    return [];
  }

}

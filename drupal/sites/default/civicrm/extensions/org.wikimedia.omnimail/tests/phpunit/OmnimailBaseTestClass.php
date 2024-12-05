<?php

require_once __DIR__ . '/GuzzleTestTrait.php';

use Civi\Api4\Contact;
use Civi\Api4\Phone;
use Civi\Test\Api3TestTrait;
use Civi\Test\EntityTrait;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Omnimail\Omnimail;
use Omnimail\Silverpop\Connector\SilverpopGuzzleXmlConnector;
use Omnimail\Silverpop\Credentials;
use Omnimail\Silverpop\Connector\SilverpopGuzzleConnector;
use PHPUnit\Framework\TestCase;
use SilverpopConnector\SilverpopRestConnector;
use SilverpopConnector\SilverpopXmlConnector;

/**
 * FIXME - Add test description.
 *
 * Tips:
 *  - With HookInterface, you may implement CiviCRM hooks directly in the test class.
 *    Simply create corresponding functions (e.g. "hook_civicrm_post(...)" or similar).
 *  - With TransactionalInterface, any data changes made by setUp() or test****() functions will
 *    rollback automatically -- as long as you don't manipulate schema or truncate tables.
 *    If this test needs to manipulate schema or truncate tables, then either:
 *       a. Do all that using setupHeadless() and Civi\Test.
 *       b. Disable TransactionalInterface, and handle all setup/teardown yourself.
 *
 * @group headless
 */
class OmnimailBaseTestClass extends TestCase {
  use Api3TestTrait;
  use GuzzleTestTrait;
  use EntityTrait;

  protected $existingSettings = [];

  /**
   * Civi\Test has many helpers, like install(), uninstall(), sql(), and sqlFile().
   *
   * See: https://github.com/civicrm/org.civicrm.testapalooza/blob/master/civi-test.md
   *
   * @return \Civi\Test\CiviEnvBuilder
   * @throws \CRM_Extension_Exception_ParseException
   */
  public function setUpHeadless(): \Civi\Test\CiviEnvBuilder {
    return \Civi\Test::headless()
      ->installMe(__DIR__)
      ->apply();
  }

  /**
   * IDs of contacts created for the test.
   *
   * @deprecated set $this->ids['Contact'] to align with the EntityTrait.
   *
   * @var array
   */
  protected $contactIDs = [];

  public function setUp(): void {
    parent::setUp();
    // This base url is only useful when using the tests to help develop - ie if you call
    // `$this->setUpClientWithHistoryContainer();` and then run an acoustic api
    // you can capture the outgoing in incoming responses. However, by the time
    // it is committed the call to `$this->setUpClientWithHistoryContainer();`
    // would be replaced to a call to `$this->getMockRequest()` which loads the
    // mock response rather than doing a live call.
    $this->setBaseUri('https://api-campaign-us-4.goacoustic.com/');
  }

  /**
   * @throws \CRM_Core_Exception
   */
  public function tearDown(): void {
    foreach ($this->contactIDs as $contactID) {
      // $this->contactIDs is the old format.
      $this->ids['Contact'][] = $contactID;
    }
    if (!empty($this->ids['Contact'])) {
      Contact::delete(FALSE)
        ->addWhere('id', 'IN', $this->ids['Contact'])
        ->setUseTrash(FALSE)
        ->execute();
    }
    $phones = (array) Phone::get(FALSE)
      ->addWhere('phone_data.recipient_id', '=', 12345)
      ->execute()
      ->indexBy('contact_id');
    if (count($phones) > 0) {
      Contact::delete(FALSE)
        ->addWhere('id', 'IN', array_keys($phones))
        ->setUseTrash(FALSE)
        ->execute();
    }

    $this->cleanupMailingData();
    CRM_Core_DAO::executeQuery('DELETE FROM civicrm_omnimail_job_progress');
    SilverpopGuzzleConnector::getInstance()->logout();
    foreach ($this->existingSettings as $key => $value) {
      $this->setSetting($key, $value);
    }
    parent::tearDown();
  }

  /**
   * Get mock guzzle client object.
   *
   * @param $body
   * @param bool $authenticateFirst
   *
   * @return \GuzzleHttp\Client
   */
  public function getMockRequest($body = [], $authenticateFirst = TRUE): Client {

    $responses = [];
    if ($authenticateFirst) {
      $this->authenticate();
      // Make sure there is a logout at the end.
      $body[] = file_get_contents(__DIR__ . '/Responses/LogoutResponse.txt');
    }
    foreach ($body as $responseBody) {
      $responses[] = new Response(200, [], $responseBody);
    }
    $this->mockHandler = new MockHandler($responses);
    $this->setUpClientWithHistoryContainer();
    return $this->getGuzzleClient();
  }

  /**
   * Authenticate with silverpop.
   *
   * It's possible we are already authenticated so we just want to fill up our mock responses and
   * try anyway. That way when the actual command runs we know it is done and the number of responses
   * used won't depend on whether a previous test authenticated earlier.
   */
  protected function authenticate() {
    $responses[] = new Response(200, [], file_get_contents(__DIR__ . '/Responses/AuthenticateResponse.txt'));
    $mock = new MockHandler($responses);
    $handler = HandlerStack::create($mock);
    $client = new Client(['handler' => $handler]);
    Omnimail::create('Silverpop', [
      'client' => $client,
      'credentials' => new Credentials(['username' => 'Shrek', 'password' => 'Fiona']),
    ])->getMailings();
  }

  /**
   * Set up the mock client to imitate a success result.
   *
   * @param string $job
   *
   * @return \GuzzleHttp\Client
   */
  protected function setupSuccessfulDownloadClient($job = 'omnimail_omnigroupmembers_load'): Client {
    $responses = [
      file_get_contents(__DIR__ . '/Responses/RawRecipientDataExportResponse.txt'),
      file_get_contents(__DIR__ . '/Responses/JobStatusCompleteResponse.txt'),
      file_get_contents(__DIR__ . '/Responses/LogoutResponse.txt'),
    ];
    //Raw Recipient Data Export Jul 02 2017 21-46-49 PM 758.zip
    copy(__DIR__ . '/Responses/Raw Recipient Data Export Jul 03 2017 00-47-42 AM 1295.csv', sys_get_temp_dir() . '/Raw Recipient Data Export Jul 03 2017 00-47-42 AM 1295.csv');
    fopen(sys_get_temp_dir() . '/Raw Recipient Data Export Jul 03 2017 00-47-42 AM 1295.csv.complete', 'c');
    $this->createSetting(['job' => $job, 'mailing_provider' => 'Silverpop', 'last_timestamp' => '1487890800']);
    return $this->getMockRequest($responses);
  }

  /**
   * Create a CiviCRM setting with some extra debugging if it fails.
   *
   * @param array $values
   */
  protected function createSetting($values) {
    foreach (['last_timestamp', 'progress_end_timestamp'] as $dateField) {
      if (!empty($values[$dateField])) {
        $values[$dateField] = gmdate('YmdHis', $values[$dateField]);
      }
    }
    try {
      civicrm_api3('OmnimailJobProgress', 'create', $values);
    }
    catch (CRM_Core_Exception $e) {
      $this->fail(print_r($values, 1), $e->getMessage() . $e->getTraceAsString() . print_r($e->getExtraParams(), TRUE));
    }
  }

  /**
   * Get job settings with dates rendered to UTC string.
   *
   * @param array $params
   *
   * @return array
   */
  public function getUtcDateFormattedJobSettings($params = ['mail_provider' => 'Silverpop']): array {
    $settings = $this->getJobSettings($params);
    $dateFields = ['last_timestamp', 'progress_end_timestamp'];
    foreach ($dateFields as $dateField) {
      if (!empty($settings[$dateField])) {
        $settings[$dateField] = date('Y-m-d H:i:s', $settings[$dateField]);
      }
    }
    // Unset this as this return array is validated against expected values
    // & we don't want to check this field.
    unset($settings['created_date']);
    return $settings;
  }

  public function createMailingProviderData(): void {
    $this->callAPISuccess('Campaign', 'create', ['name' => 'xyz', 'title' => 'Cool Campaign']);
    $this->callAPISuccess('Mailing', 'create', ['campaign_id' => 'xyz', 'hash' => 'xyz', 'name' => 'Mail Unit Test']);

    $this->callAPISuccess('MailingProviderData', 'create', [
      'contact_id' => $this->contactIDs['charlie_clone'],
      'email' => 'charlie@example.com',
      'event_type' => 'Opt Out',
      'mailing_identifier' => 'xyz',
      'recipient_action_datetime' => '2017-02-02',
      'contact_identifier' => 'a',
    ]);
    $this->callAPISuccess('MailingProviderData', 'create', [
      'contact_id' => $this->contactIDs['marie'],
      'event_type' => 'Open',
      'email' => 'bob@example.com',
      'mailing_identifier' => 'xyz',
      'recipient_action_datetime' => '2017-03-03',
      'contact_identifier' => 'b',
    ]);
    $this->callAPISuccess('MailingProviderData', 'create', [
      'contact_id' => $this->contactIDs['isaac'],
      'event_type' => 'Suppressed',
      'mailing_identifier' => 'xyuuuz',
      'recipient_action_datetime' => '2017-04-04',
      'contact_identifier' => 'c',
    ]);
    $this->callAPISuccess('MailingProviderData', 'create', [
      'contact_id' => $this->contactIDs['isaac'],
      'email' => 'charlie@example.com',
      'event_type' => 'Hard Bounce',
      'mailing_identifier' => 'xyuuuz',
      'recipient_action_datetime' => '2017-05-04',
      'contact_identifier' => 'c',
    ]);
  }

  /**
   * Cleanup test setup data.
   *
   * @throws \Civi\Core\Exception\DBQueryException
   */
  protected function cleanupMailingData(): void {
    CRM_Core_DAO::executeQuery("DELETE FROM civicrm_mailing_provider_data WHERE mailing_identifier IN (
      'xyz', 'xyuuuz'
   )");
    CRM_Core_DAO::executeQuery("DELETE FROM civicrm_mailing WHERE name = 'Mail Unit Test'");
    CRM_Core_DAO::executeQuery("DELETE FROM civicrm_campaign WHERE name = 'xyz'");
    CRM_Core_DAO::executeQuery('DELETE FROM civicrm_queue_item WHERE queue_name = "omni-snooze"');
    CRM_Core_DAO::executeQuery('DELETE FROM civicrm_queue WHERE name = "omni-snooze"');
    \CRM_Queue_Service::singleton(TRUE);
  }

  protected function makeScientists() {
    $contact = $this->callAPISuccess('Contact', 'create', [
      'first_name' => 'Charles',
      'last_name' => 'Darwin',
      'contact_type' => 'Individual',
    ]);
    $this->contactIDs['charlie'] = $contact['id'];
    $contact = $this->callAPISuccess('Contact', 'create', [
      'first_name' => 'Charlie',
      'last_name' => 'Darwin',
      'contact_type' => 'Individual',
      'api.email.create' => [
        'is_bulkmail' => 1,
        'email' => 'charlie@example.com',
      ],
    ]);
    $this->contactIDs['charlie_clone'] = $contact['id'];

    $contact = $this->callAPISuccess('Contact', 'create', [
      'first_name' => 'Marie',
      'last_name' => 'Currie',
      'contact_type' => 'Individual',
    ]);
    $this->contactIDs['marie'] = $contact['id'];
    $contact = $this->callAPISuccess('Contact', 'create', [
      'first_name' => 'Isaac',
      'last_name' => 'Newton',
      'contact_type' => 'Individual',
    ]);
    $this->contactIDs['isaac'] = $contact['id'];
  }

  /**
   * Set up the mock handler for an erase request.
   *
   * @param int $connectionCount
   */
  protected function setUpForErase($connectionCount = 1) {
    $files = ['/Responses/AuthenticateRestResponse.txt'];
    $i = 0;
    while ($i < $connectionCount) {
      // These files consist of the Authenticate request and the 'status pending'.
      // which is re-tried a handful of times. We never get a reply because in my tests it took
      // > 15 mins & our process won't hang around for that.
      array_push($files,
        '/Responses/Privacy/EraseInitialResponse.txt',
        '/Responses/Privacy/EraseInProgressResponse.txt',
        '/Responses/Privacy/EraseInProgressResponse.txt',
        '/Responses/Privacy/EraseInProgressResponse.txt',
        '/Responses/Privacy/EraseInProgressResponse.txt',
        '/Responses/Privacy/EraseInProgressResponse.txt',
        '/Responses/Privacy/EraseInProgressResponse.txt'
      );
      $i++;
    }
    $files[] = '/Responses/LogoutResponse.txt';

    $this->createMockHandlerForFiles($files);
    $this->setUpClientWithHistoryContainer();
  }

  /**
   * Set up the mock handler for an erase request.
   */
  protected function setUpForEraseFollowUpSuccess() {
    $files = [
      '/Responses/AuthenticateRestResponse.txt',
      '/Responses/Privacy/EraseInProgressResponse.txt',
      '/Responses/Privacy/EraseSuccessResponse.txt',
    ];

    $this->createMockHandlerForFiles($files);
    $this->setUpClientWithHistoryContainer();
  }

  /**
   * Ensure there is a database id setting.
   *
   * @param array|int $databaseIDs
   *
   * @return array
   *   Settings prior to change
   */
  protected function setDatabaseID($databaseIDs = [50]): array {
    $credentials = Civi::settings()->get('omnimail_credentials');
    // This won't actually work if settings is set in civicrm.settings.php but will be used by CI
    // which now will skip erase if it doesn't have any database_id
    $this->setSetting('omnimail_credentials', ['Silverpop' => array_merge($credentials['Silverpop'] ?? [], ['database_id' => (array) $databaseIDs])]);
    return $credentials;
  }

  /**
   * This sets the setting for the test, recording the original value to reset.
   *
   * Note that this overrides any 'mandatory' setting (ie one recorded in
   * civicrm.settings.php or, in our case, in fundraising-dev/config/civicrm/settings.d
   *
   * @param string $setting
   * @param array $temporarySettings
   */
  protected function setSetting(string $setting, array $temporarySettings): void {
    $this->existingSettings[$setting] = Civi::settings()->get($setting);
    Civi::settings()->set($setting, $temporarySettings);
    // Settings stored in the global are 'mandatory' - ie override the db.
    global $civicrm_setting;
    if (isset($civicrm_setting['domain'][$setting])) {
      $civicrm_setting['domain'][$setting] = $temporarySettings;
      Civi::service('settings_manager')->flush();
    }
  }

  /**
   * Add our mock client to the rest singleton.
   *
   * In other places we have been passing the client in but we can't do that
   * here so trying a new tactic  - basically setting it up on the singleton
   * first.
   */
  protected function addTestClientToRestSingleton() {
    $restConnector = SilverpopRestConnector::getInstance();
    $this->setUpClientWithHistoryContainer();
    $restConnector->setClient($this->getGuzzleClient());
  }

  /**
   * Add our mock client to the rest singleton.
   *
   * In other places we have been passing the client in but we can't do that
   * here so trying a new tactic  - basically setting it up on the singleton
   * first.
   */
  protected function addTestClientToXMLSingleton(): void {
    /** @var SilverpopXmlConnector $connector */
    $connector = SilverpopGuzzleXmlConnector::getInstance();
    $connector->setClient($this->getGuzzleClient());
  }


  /**
   * @param string $snoozeDate
   *
   * @return array
   */
  public function createSnoozyDuck(string $snoozeDate): array {
    // These values are passed into the api call in other tests. But, because in this
    // case the hook queues up the database update we need a more 'global' approach.
    $this->setDatabaseID(1234);
    return $this->createTestEntity('Contact', [
      'contact_type' => 'Individual',
      'first_name' => 'Donald',
      'last_name' => 'Duck',
      'email_primary.email' => 'the_don@example.com',
      'email_primary.email_settings.snooze_date' => $snoozeDate,
    ], 'snoozy');
  }

}

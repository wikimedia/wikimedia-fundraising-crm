<?php

use Civi\Test\EndToEndInterface;
use Civi\Test\HookInterface;
use Civi\Test\TransactionalInterface;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;

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
 * @group e2e
 */
class OmnimailBaseTestClass extends \PHPUnit_Framework_TestCase implements EndToEndInterface, TransactionalInterface {

  public function setUp() {
    civicrm_initialize();
    parent::setUp();
    $null = NULL;
    Civi::service('settings_manager')->flush();
    \Civi::$statics['_omnimail_settings'] = array();
  }

  /**
   * Get mock guzzle client object.
   *
   * @param $body
   * @param bool $authenticateFirst
   * @return \GuzzleHttp\Client
   */
  public function getMockRequest($body = array(), $authenticateFirst = TRUE) {

    $responses = array();
    if ($authenticateFirst) {
      $responses[] = new Response(200, [], file_get_contents(__DIR__ . '/Responses/AuthenticateResponse.txt'));
    }
    foreach ($body as $responseBody) {
      $responses[] = new Response(200, [], $responseBody);
    }
    $mock = new MockHandler($responses);
    $handler = HandlerStack::create($mock);
    return new Client(array('handler' => $handler));
  }


  /**
   * Set up the mock client to imitate a success result.
   *
   * @param string $job
   *
   * @return \GuzzleHttp\Client
   */
  protected function setupSuccessfulDownloadClient($job = 'omnimail_omnigroupmembers_load') {
    $responses = array(
      file_get_contents(__DIR__ . '/Responses/RawRecipientDataExportResponse.txt'),
      file_get_contents(__DIR__ . '/Responses/JobStatusCompleteResponse.txt'),
    );
    //Raw Recipient Data Export Jul 02 2017 21-46-49 PM 758.zip
    copy(__DIR__ . '/Responses/Raw Recipient Data Export Jul 03 2017 00-47-42 AM 1295.csv', sys_get_temp_dir() . '/Raw Recipient Data Export Jul 03 2017 00-47-42 AM 1295.csv');
    fopen(sys_get_temp_dir() . '/Raw Recipient Data Export Jul 03 2017 00-47-42 AM 1295.csv.complete', 'c');
    $this->createSetting(array('job' => $job, 'mailing_provider' => 'Silverpop', 'last_timestamp' => '1487890800'));
    $client = $this->getMockRequest($responses);
    return $client;
  }

  /**
   * Create a CiviCRM setting with some extra debugging if it fails.
   *
   * @param array $values
   */
  protected function createSetting($values) {
    foreach (array('last_timestamp', 'progress_end_timestamp') as $dateField) {
      if (!empty($values[$dateField])) {
        $values[$dateField] = gmdate('YmdHis', $values[$dateField]);
      }
    }
    try {
      civicrm_api3('OmnimailJobProgress', 'create', $values);
    }
    catch (CiviCRM_API3_Exception $e) {
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
  public function getUtcDateFormattedJobSettings($params = array('mail_provider' => 'Silverpop')) {
     $settings = $this->getJobSettings($params);
     $dateFields = array('last_timestamp', 'progress_end_timestamp');
     foreach ($dateFields as $dateField) {
       if (!empty($settings[$dateField])) {
         $settings[$dateField] = date('Y-m-d H:i:s', $settings[$dateField]);
       }
     }
     return $settings;
  }

}

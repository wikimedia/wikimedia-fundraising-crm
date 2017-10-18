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
   * @param array $value
   */
  protected function createSetting($value) {
    $keysToUnset = array(
      'mailing_provider',
      'job',
      'job_identifier',
    );
    try {
      // This sequence for merging in the suffix version is temporary to assist refactoring.
      // Otherwise it became too big & busy to read all the 'real' changes.
      $result = civicrm_api3('Setting', 'get', array('return' => $value['job']));
      if (isset($result ['values'][CRM_Core_Config::domainID()][$value['job']])) {
        $existingSettings = $result['values'][CRM_Core_Config::domainID()][$value['job']];
      }
      else {
        $existingSettings = array();
      }
      $key = $value['mailing_provider'] . (isset($value['job_identifier']) ? $value['job_identifier'] : '');

      foreach (array_keys($existingSettings) as $existingKey) {
        if ($existingKey === $key) {
          unset($existingSettings[$existingKey]);
        }
      }

      civicrm_api3('Setting', 'create', array(
        'debug' => 1,
        $value['job'] => array_merge($existingSettings, array(
        $key  => array_diff_key(
          $value, array_fill_keys($keysToUnset, 1)
      )))));
    }
    catch (CiviCRM_API3_Exception $e) {
      $settings = \Civi\Core\SettingsMetadata::getMetadata();
      $this->fail(print_r(array_keys($settings), 1), $e->getMessage() . $e->getTraceAsString() . print_r($e->getExtraParams(), TRUE));
    }
  }
}

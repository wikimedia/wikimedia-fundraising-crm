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
    civicrm_api3('Setting', 'getfields', array('cache_clear' => 1));
    \Civi::cache('settings')->set('settingsMetadata_' . \CRM_Core_Config::domainID() . '_', $null);
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
   * @return \GuzzleHttp\Client
   */
  protected function setupSuccessfulDownloadClient() {
    $responses = array(
      file_get_contents(__DIR__ . '/Responses/RawRecipientDataExportResponse.txt'),
      file_get_contents(__DIR__ . '/Responses/JobStatusCompleteResponse.txt'),
    );
    //Raw Recipient Data Export Jul 02 2017 21-46-49 PM 758.zip
    copy(__DIR__ . '/Responses/Raw Recipient Data Export Jul 03 2017 00-47-42 AM 1295.csv', sys_get_temp_dir() . '/Raw Recipient Data Export Jul 03 2017 00-47-42 AM 1295.csv');
    fopen(sys_get_temp_dir() . '/Raw Recipient Data Export Jul 03 2017 00-47-42 AM 1295.csv.complete', 'c');
    $this->createSetting('omnimail_omnirecipient_load',array('Silverpop' => array('last_timestamp' => '1487890800'),));
    $client = $this->getMockRequest($responses);
    return $client;
  }

  /**
   * Create a CiviCRM setting with some extra debugging if it fails.
   *
   * @param $setting
   * @param $value
   */
  protected function createSetting($setting, $value) {
    try {
      civicrm_api3('Setting', 'create', array(
        'debug' => 1,
        $setting => $value,
      ));
    } catch (CiviCRM_API3_Exception $e) {
      $settings = \Civi\Core\SettingsMetadata::getMetadata();
      $this->fail(print_r(array_keys($settings), 1), $e->getMessage() . $e->getTraceAsString() . print_r($e->getExtraParams(), TRUE));
    }
  }
}
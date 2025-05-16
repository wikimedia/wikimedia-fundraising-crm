<?php

use Civi\Test\EndToEndInterface;
use Civi\Test\HookInterface;
use Civi\Test\TransactionalInterface;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
require_once __DIR__ . '/OmnimailBaseTestClass.php';

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
class OmnigroupmemberGetTest extends OmnimailBaseTestClass {

  /**
   * Example: Test that a version is returned.
   *
   * @throws \CRM_Core_Exception
   */
  public function testOmnigroupmemberGet() {
    $client = $this->setupSuccessfulDownloadClient();

    $result = civicrm_api3('Omnigroupmember', 'get', array('mail_provider' => 'Silverpop', 'username' => 'Shrek', 'password' => 'Fiona', 'options' => array('limit' => 3), 'client' => $client, 'group_identifier' => 123));
    $this->assertEquals(3, $result['count']);
    $this->assertEquals('eric@example.com', $result['values'][0]['email']);
    $this->assertEquals('', $result['values'][0]['contact_id']);
    $this->assertEquals(TRUE, $result['values'][0]['is_opt_out']);
    $this->assertEquals('2016-10-18 20:01:00', $result['values'][0]['opt_in_date']);
    $this->assertEquals('2017-07-04 11:11:00', $result['values'][0]['opt_out_date']);
    $this->assertEquals('Added by WebForms', $result['values'][0]['opt_in_source']);
    $this->assertEquals('Opt out via email opt out.', $result['values'][0]['opt_out_source']);
    $this->assertEquals('clever place', $result['values'][2]['source']);
    $this->assertEquals('US', $result['values'][2]['country']);
    $this->assertEquals('en', $result['values'][2]['language']);
    $this->assertEquals('07/04/17', $result['values'][2]['created_date']);
  }


  /**
   * @param string $job
   *
   *@return \GuzzleHttp\Client
   *
   */
  protected function setupSuccessfulDownloadClient(string $job = 'omnimail_omnigroupmembers_load'): Client {
    $responses = array(
      file_get_contents(__DIR__ . '/Responses/ExportListResponse.txt'),
      file_get_contents(__DIR__ . '/Responses/JobStatusCompleteResponse.txt'),
      file_get_contents(__DIR__ . '/Responses/LogoutResponse.txt'),
    );
    copy(__DIR__ . '/Responses/20170509_noCID - All - Jul 5 2017 06-27-45 AM.csv', sys_get_temp_dir() . '/20170509_noCID - All - Jul 5 2017 06-27-45 AM.csv');
    fopen(sys_get_temp_dir() . '/20170509_noCID - All - Jul 5 2017 06-27-45 AM.csv.complete', 'c');
    $this->createSetting(array('job' => $job, 'mailing_provider' => 'Silverpop', 'last_timestamp' => '1487890800'));

    $client = $this->getMockRequest($responses);
    return $client;
  }
}

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
class OmnihellGetTest extends OmnimailBaseTestClass {

  /**
   * Example: Test that a version is returned.
   */
  public function testOmnihellGet() {
    $client = $this->setupSuccessfulBrowserClient();

    $hell = $this->callApiSuccess('Omnihell', 'get', [
      'mail_provider' => 'Silverpop',
      'username' => 'Silver',
      'password' => 'pop',
      'client' => $client,
      'list_id' => '26726699',
    ])['values'];
    $this->assertEquals(["WHEN (COUNTRY is equal to IL AND ISOLANG is equal to HE AND LATEST_DONATION_DATE is before JAN 1, 2019 AND EMAIL_DOMAIN_PART is not equal to one of the following (AOL.COM | NETSCAPE.COM | NETSCAPE.NET | CS.COM | AIM.COM | WMCONNECT.COM | VERIZON.NET) OR (EMAIL is equal to FUNDRAISINGEMAIL-JAJP+HEIL@WIKIMEDIA.ORG AND COUNTRY is equal to IL)) AND SEGMENT is equal to 2"], $hell);
  }

  /**
   * Set up the mock client to imitate a success result.
   *
   * @param string $job
   *
   * @return \GuzzleHttp\Client
   */
  protected function setupSuccessfulBrowserClient($job = 'omnimail_omnigroupmembers_load'): Client {
    $responses = [
      file_get_contents(__DIR__ . '/Responses/LoginHtml.html'),
      '',
      file_get_contents(__DIR__ . '/Responses/QueryListHtml.html'),
    ];
    return $this->getMockRequest($responses, FALSE);
  }

}

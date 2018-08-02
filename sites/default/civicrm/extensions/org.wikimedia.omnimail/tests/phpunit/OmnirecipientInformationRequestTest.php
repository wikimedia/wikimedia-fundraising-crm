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
 * @group e2e
 */
class OmnirecipientInformationRequestTest extends OmnimailBaseTestClass implements EndToEndInterface, TransactionalInterface {

  use GuzzleTestTrait;

  public function setUpHeadless() {
    // Civi\Test has many helpers, like install(), uninstall(), sql(), and sqlFile().
    // See: https://github.com/civicrm/org.civicrm.testapalooza/blob/master/civi-test.md
    return \Civi\Test::e2e()
      ->installMe(__DIR__)
      ->apply();
  }

  public function setUp() {
    parent::setUp();
  }

  public function tearDown() {
    parent::tearDown();
  }

  /**
   * Example: Test that a version is returned.
   */
  public function testOmnirecipientInformationRequest() {
    $this->createMockHandlerForFiles([
      '/Responses/AuthenticateRestResponse.txt',
      '/Responses/Privacy/PrivacyRequest1.txt',
      '/Responses/Privacy/PrivacyRequest2.txt',
      '/Responses/Privacy/PrivacyRequest3.txt',
    ]);
    $this->setUpClientWithHistoryContainer();
    $result = $this->callAPISuccess('Omnirecipient', 'informationrequest', [
      'mail_provider' => 'Silverpop',
      'client' => $this->getGuzzleClient(),
      'email' => 'eileen@example.com',
      'client_id' => 'secrethandshake',
      'client_secret' => 'waggleleftthumb',
      'refresh_token' => 'thenrightone',
    ])['values'];

    $this->assertEquals(1417692990, $result->getOptInTimestamp());
    $requests = $this->getRequestBodies();
    $this->assertEquals($requests[0], trim(file_get_contents(__DIR__ . '/Requests/AuthenticateRest.txt')));
    $this->assertEquals($requests[1], file_get_contents(__DIR__ . '/Requests/privacy_csv.txt'));
  }

}

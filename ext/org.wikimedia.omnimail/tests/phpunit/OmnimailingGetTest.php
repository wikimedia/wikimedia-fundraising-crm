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
class OmnimailingGetTest extends OmnimailBaseTestClass {

  /**
   * Example: Test that a version is returned.
   */
  public function testOmnimailingGet(): void {
    $responses = [
      file_get_contents(__DIR__ . '/Responses/MailingGetResponse1.txt'),
      file_get_contents(__DIR__ . '/Responses/MailingGetResponse2.txt'),
      file_get_contents(__DIR__ . '/Responses/AggregateGetResponse1.txt'),
      file_get_contents(__DIR__ . '/Responses/AggregateGetResponse2.txt'),
      file_get_contents(__DIR__ . '/Responses/GetMailingTemplateResponse.txt'),
      file_get_contents(__DIR__ . '/Responses/GetMailingTemplateResponse2.txt'),
      file_get_contents(__DIR__ . '/Responses/GetQueryResponse.txt'),
      file_get_contents(__DIR__ . '/Responses/GetQueryResponse.txt'),
      file_get_contents(__DIR__ . '/Responses/LogoutResponse.txt'),
    ];

    $mailings = $this->callAPISuccess('Omnimailing', 'get', [
      'mail_provider' => 'Silverpop',
      'client' => $this->getMockRequest($responses),
      'username' => 'Donald',
      'password' => 'quack'
    ]);
    $this->assertEquals(2, $mailings['count']);
    $firstMailing = $mailings['values'][0];
    $this->assertEquals('cool email 🌻', $firstMailing['subject']);
    $this->assertEquals('( is in contact list 1234567 AND Segment is equal to 328 AND latest_donation_date is before 01/01/2019 ) OR Email is equal to info@examplee.org', $firstMailing['list_string']);
  }

}

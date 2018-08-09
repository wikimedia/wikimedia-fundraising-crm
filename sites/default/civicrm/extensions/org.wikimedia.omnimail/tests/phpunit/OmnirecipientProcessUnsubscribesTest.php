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
class OmnirecipientProcessUnsubscribesTest extends OmnimailBaseTestClass implements EndToEndInterface, TransactionalInterface {

  public function setUpHeadless() {
    // Civi\Test has many helpers, like install(), uninstall(), sql(), and sqlFile().
    // See: https://github.com/civicrm/org.civicrm.testapalooza/blob/master/civi-test.md
    return \Civi\Test::e2e()
      ->installMe(__DIR__)
      ->apply();
  }

  public function setUp() {
    parent::setUp();
    $this->makeScientists();
  }

  public function tearDown() {
    parent::tearDown();
  }

  /**
   * Example: Test that a version is returned.
   */
  public function testOmnirecipientProcessUnsubscribes() {

    $this->createMailingProviderData();
    civicrm_api3('Omnirecipient', 'process_unsubscribes', array('mail_provider' => 'Silverpop'));
    $data = civicrm_api3('MailingProviderData', 'get', array('sequential' => 1));
    $this->assertEquals(1, $data['values'][0]['is_civicrm_updated']);
    $contact = civicrm_api3('Contact', 'getsingle', array('id' => $this->contactIDs['charlie_clone']));
    $this->assertEquals(1, $contact['is_opt_out']);
    $email = civicrm_api3('Email', 'getsingle', array('email' => 'charlie@example.com'));
    $this->assertEquals(0, $email['is_bulkmail']);
    $activity = civicrm_api3('Activity', 'getsingle', array('contact_id' => $this->contactIDs['charlie_clone']));
    $this->assertEquals('Unsubscribed via Silverpop', $activity['subject']);

    $contact = civicrm_api3('Contact', 'getsingle', array('id' => $this->contactIDs['marie']));
    $this->assertEquals(0, $contact['is_opt_out']);

    $contact = civicrm_api3('Contact', 'getsingle', array('id' => $this->contactIDs['isaac']));
    $this->assertEquals(1, $contact['is_opt_out']);

  }

}

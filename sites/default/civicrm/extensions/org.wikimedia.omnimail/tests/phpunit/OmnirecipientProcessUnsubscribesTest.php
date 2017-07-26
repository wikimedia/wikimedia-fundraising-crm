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

  /**
   * IDs of contacts created for the test.
   *
   * @var array
   */
  protected $contactIDs = array();

  public function setUpHeadless() {
    // Civi\Test has many helpers, like install(), uninstall(), sql(), and sqlFile().
    // See: https://github.com/civicrm/org.civicrm.testapalooza/blob/master/civi-test.md
    return \Civi\Test::e2e()
      ->installMe(__DIR__)
      ->apply();
  }

  public function setUp() {
    parent::setUp();
    Civi::service('settings_manager')->flush();
    $contact = civicrm_api3('Contact', 'create', array('first_name' => 'Charles', 'last_name' => 'Darwin', 'contact_type' => 'Individual'));
    $this->contactIDs[] = $contact['id'];
    $contact = civicrm_api3('Contact', 'create', array('first_name' => 'Charlie', 'last_name' => 'Darwin', 'contact_type' => 'Individual', 'api.email.create' => array('is_bulkmail' => 1, 'email' => 'charlie@example.com')));
    $this->contactIDs[] = $contact['id'];

    $contact = civicrm_api3('Contact', 'create', array('first_name' => 'Marie', 'last_name' => 'Currie', 'contact_type' => 'Individual'));
    $this->contactIDs[] = $contact['id'];
    $contact = civicrm_api3('Contact', 'create', array('first_name' => 'Isaac', 'last_name' => 'Newton', 'contact_type' => 'Individual'));
    $this->contactIDs[] = $contact['id'];
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
    $contact = civicrm_api3('Contact', 'getsingle', array('id' => $this->contactIDs[0]));
    $this->assertEquals(1, $contact['is_opt_out']);
    $email = civicrm_api3('Email', 'getsingle', array('email' => 'charlie@example.com'));
    $this->assertEquals(0, $email['is_bulkmail']);
    $activity = civicrm_api3('Activity', 'getsingle', array('contact_id' => $this->contactIDs[0]));
    $this->assertEquals('Unsubscribed via Silverpop', $activity['subject']);

    $contact = civicrm_api3('Contact', 'getsingle', array('id' => $this->contactIDs[2]));
    $this->assertEquals(0, $contact['is_opt_out']);

    $contact = civicrm_api3('Contact', 'getsingle', array('id' => $this->contactIDs[3]));
    $this->assertEquals(1, $contact['is_opt_out']);

  }

  public function createMailingProviderData() {
    civicrm_api3('Campaign', 'create', array('name' => 'xyz', 'title' => 'Cool Campaign'));
    civicrm_api3('Mailing', 'create', array('campaign_id' => 'xyz', 'hash' => 'xyz', 'name' => 'Mail'));
    civicrm_api3('MailingProviderData', 'create',  array(
      'contact_id' => $this->contactIDs[0],
      'email' => 'charlie@example.com',
      'event_type' => 'Opt Out',
      'mailing_identifier' => 'xyz',
      'recipient_action_datetime' => '2017-02-02',
      'contact_identifier' => 'a',
    ));
    civicrm_api3('MailingProviderData', 'create',  array(
      'contact_id' => $this->contactIDs[2],
      'event_type' => 'Open',
      'mailing_identifier' => 'xyz',
      'recipient_action_datetime' => '2017-03-03',
      'contact_identifier' => 'b',
    ));
    civicrm_api3('MailingProviderData', 'create',  array(
      'contact_id' => $this->contactIDs[3],
      'event_type' => 'Suppressed',
      'mailing_identifier' => 'xyuuuz',
      'recipient_action_datetime' => '2017-04-04',
      'contact_identifier' => 'c',
    ));
  }

}

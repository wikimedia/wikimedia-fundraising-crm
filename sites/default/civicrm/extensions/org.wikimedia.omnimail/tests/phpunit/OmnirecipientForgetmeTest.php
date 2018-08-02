<?php

use Civi\Test\EndToEndInterface;
use Civi\Test\TransactionalInterface;
use SilverpopConnector\SilverpopRestConnector;

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
class OmnirecipientForgetmeTest extends OmnimailBaseTestClass implements EndToEndInterface, TransactionalInterface {

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
    CRM_Core_DAO::executeQuery('DELETE FROM civicrm_mailing_provider_data WHERE contact_id IN (' . implode(',', $this->contactIDs) . ')');
    parent::tearDown();
  }

  /**
   * Test forgetme function.
   */
  public function testForgetme() {
    $this->makeScientists();
    $this->createMailingProviderData();

    $this->setUpForErase();
    $this->addTestClientToRestSingleton();

    $this->assertEquals(1, $this->callAPISuccessGetCount('MailingProviderData', ['contact_id' => $this->contactIDs[0]]));

    $this->callAPISuccess('Contact', 'forgetme', ['id' => $this->contactIDs[0]]);

    $this->assertEquals(0, $this->callAPISuccessGetCount('MailingProviderData', ['contact_id' => $this->contactIDs[0]]));

    // Check the request we sent out had the right email in it.
    $requests = $this->getRequestBodies();
    $this->assertEquals($requests[1], "Email,charlie@example.com\n");
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
    $restConnector->setClient($this->getClient());
  }

}

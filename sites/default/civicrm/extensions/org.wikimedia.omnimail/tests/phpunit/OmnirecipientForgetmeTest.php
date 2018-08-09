<?php

use Civi\Test\EndToEndInterface;
use Civi\Test\TransactionalInterface;

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
    $this->assertEquals(1, $this->callAPISuccessGetCount('MailingProviderData', ['contact_id' => $this->contactIDs['charlie_clone']]));

    $this->callAPISuccess('Contact', 'forgetme', ['id' => $this->contactIDs['charlie_clone']]);

    $this->assertEquals(0, $this->callAPISuccessGetCount('MailingProviderData', ['contact_id' => $this->contactIDs['charlie_clone']]));
  }

}

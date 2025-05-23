<?php

require_once __DIR__ . '/OmnimailBaseTestClass.php';

/**
 * FIXME - Add test description.
 *
 * Tips:
 *  - With HookInterface, you may implement CiviCRM hooks directly in the test
 * class. Simply create corresponding functions (e.g. "hook_civicrm_post(...)"
 * or similar).
 *  - With TransactionalInterface, any data changes made by setUp() or
 * test****() functions will rollback automatically -- as long as you don't
 * manipulate schema or truncate tables. If this test needs to manipulate
 * schema or truncate tables, then either: a. Do all that using setupHeadless()
 * and Civi\Test. b. Disable TransactionalInterface, and handle all
 * setup/teardown yourself.
 *
 * @group headless
 */
class OmnirecipientProcessOnHoldTest extends OmnimailBaseTestClass {

  public function setUp(): void {
    parent::setUp();
    $this->makeScientists();
  }

  /**
   * Example: Test that a version is returned.
   */
  public function testOmnirecipientProcessOnHold(): void {
    $this->createMailingProviderData();
    $this->callAPISuccess('Omnirecipient', 'process_onhold', ['mail_provider' => 'Silverpop']);
    $data = $this->callAPISuccess('MailingProviderData', 'get', ['sequential' => 1]);
    $this->assertEquals(1, $data['values'][3]['is_civicrm_updated']);
    $email = $this->callAPISuccess('Email', 'getsingle', ['email' => 'charlie@example.com']);
    $this->assertEquals(1, $email['on_hold']);
  }

}

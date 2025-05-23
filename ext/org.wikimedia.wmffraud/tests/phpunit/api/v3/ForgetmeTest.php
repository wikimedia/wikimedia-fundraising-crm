<?php

use Civi\Test\HeadlessInterface;
use Civi\Test\HookInterface;
use Civi\Test\TransactionalInterface;

require_once __DIR__ . '/BaseTestClass.php';

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
class api_v3_ForgetmeTest extends api_v3_BaseTestClass implements HeadlessInterface, HookInterface, TransactionalInterface {

  /**
   * Test forget me.
   *
   * Both entries for contact one should be deleted but not the contact 2 entry.
   */
  public function testForgetMe(): void {
    $contact = $this->callAPISuccess('Contact', 'create', ['contact_type' => 'Individual', 'first_name' => 'Santa', 'last_name' => 'Claws']);
    $this->createContributionEntriesWithFredge(['contact_id' => $contact['id'], 'order_id' => 'your-order']);
    $this->createContributionEntriesWithFredge(['contact_id' => $contact['id'], 'order_id' => 'my-order']);

    $contact2 = $this->callAPISuccess('Contact', 'create', ['contact_type' => 'Individual', 'first_name' => 'Bobby', 'last_name' => 'Claws']);
    $this->createContributionEntriesWithFredge(['contact_id' => $contact2['id'], 'order_id' => 'his-order']);

    $this->callAPISuccess('Fredge', 'forgetme', ['contact_id' => $contact['id']]);
    $fredges = $this->callAPISuccess('Fredge', 'get', ['contact_id' => $contact['id']]);
    $this->assertEquals(2, $fredges['count']);
    foreach ($fredges['values'] as $fredge) {
      $this->assertEquals('null', $fredge['user_ip']);
    }

    $fredges = $this->callAPISuccess('Fredge', 'get', ['contact_id' => $contact2['id']]);
    $this->assertEquals(1, $fredges['count']);
    $this->assertEquals('his-order', $fredges['values'][$fredges['id']]['order_id']);
    $this->assertEquals('3232235777', $fredges['values'][$fredges['id']]['user_ip']);
  }

  /**
   * Test forget me.
   *
   * Check that missing fredge entries don't cause chaos & calamity.
   */
  public function testForgetMeNoFredge() {
    $contact = $this->callAPISuccess('Contact', 'create', ['contact_type' => 'Individual', 'first_name' => 'Santa', 'last_name' => 'Claws']);
    $this->createContributionEntriesWithTracking(['contact_id' => $contact['id'], 'order_id' => 'your-order']);
    $this->createContributionEntriesWithFredge(['contact_id' => $contact['id'], 'order_id' => 'my-order']);

    $contact2 = $this->callAPISuccess('Contact', 'create', ['contact_type' => 'Individual', 'first_name' => 'Bobby', 'last_name' => 'Claws']);
    $this->createContributionEntriesWithTracking(['contact_id' => $contact2['id'], 'order_id' => 'his-order']);

    $this->callAPISuccess('Fredge', 'forgetme', ['contact_id' => $contact['id']]);
    $fredges = $this->callAPISuccess('Fredge', 'get', ['contact_id' => $contact['id']]);
    $this->assertEquals(1, $fredges['count']);
    foreach ($fredges['values'] as $fredge) {
      $this->assertEquals('null', $fredge['user_ip']);
    }
    $this->callAPISuccess('Fredge', 'forgetme', ['contact_id' => $contact2['id']]);
  }

}

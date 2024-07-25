<?php

use CRM_WMFFraud_ExtensionUtil as E;
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
class api_v3_ShowmeTest extends api_v3_BaseTestClass implements HeadlessInterface, HookInterface, TransactionalInterface {

  /**
   * Example: Test that showme returns displayable rows.
   */
  public function testShowMe() {
    $contact = $this->callAPISuccess('Contact', 'create', ['contact_type' => 'Individual', 'first_name' => 'Santa', 'last_name' => 'Claws']);
    $this->createContributionEntriesWithFredge(['contact_id' => $contact['id'], 'order_id' => 'your-order']);
    $this->createContributionEntriesWithFredge(['contact_id' => $contact['id'], 'order_id' => 'my-order']);

    $showMe = civicrm_api3('Fredge', 'showme', ['contact_id' => $contact['id']])['showme'];
    $this->assertEquals(2, count($showMe));
    $row = array_pop($showMe);
    $this->assertEquals('Gateway:test|Order ID:my-order|Validation:accept|IP Address:3232235777|Payment Method:tooth-fairy|Risk Score:10|Date:2017-05-20 00:00:00', $row);

    $row = array_pop($showMe);
    $this->assertEquals('Gateway:test|Order ID:your-order|Validation:accept|IP Address:3232235777|Payment Method:tooth-fairy|Risk Score:10|Date:2017-05-20 00:00:00', $row);
  }

  /**
   * Test fredge rows are in contact showme results.
   */
  public function testContactShowMe() {
    $contact = $this->callAPISuccess('Contact', 'create', ['contact_type' => 'Individual', 'first_name' => 'Santa', 'last_name' => 'Claws']);
    $this->createContributionEntriesWithFredge(['contact_id' => $contact['id'], 'order_id' => 'your-order']);
    $this->createContributionEntriesWithFredge(['contact_id' => $contact['id'], 'order_id' => 'my-order']);

    $showMe = civicrm_api3('Contact', 'showme', ['id' => $contact['id']]);
    $row = $showMe['values'][$showMe['id']];
    $fredges = [];
    foreach ($row as $key => $value) {
      if (substr($key, 0, 6) === 'Fredge') {
        $fredges[] = $value;
      }
    }
    $this->assertEquals('Gateway:test|Order ID:your-order|Validation:accept|IP Address:3232235777|Payment Method:tooth-fairy|Risk Score:10|Date:2017-05-20 00:00:00', $fredges[0]);
    $this->assertEquals('Gateway:test|Order ID:my-order|Validation:accept|IP Address:3232235777|Payment Method:tooth-fairy|Risk Score:10|Date:2017-05-20 00:00:00', $fredges[1]);
  }

  /**
   * Test contributions with no fredge are still fine.
   */
  public function testShowMeContributionsNoFredge() {
    $contact = $this->callAPISuccess('Contact', 'create', ['contact_type' => 'Individual', 'first_name' => 'Santa', 'last_name' => 'Claws']);
    $this->createContributionEntries(['contact_id' => $contact['id'], 'order_id' => 'your-order']);
    $this->callAPISuccess('Contact', 'showme', ['id' => $contact['id']]);
  }

}

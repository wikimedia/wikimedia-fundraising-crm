<?php

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
class MergeTest extends OmnimailBaseTestClass {

  /**
   * Test that mailing provider data is moved on merge.
   *
   * The data against the second contact should be moved to the first.
   *
   * @throws \CRM_Core_Exception
   */
  public function testMerge() {
    $params = ['contact_type' => 'Individual', 'first_name' => 'Ayra', 'last_name' => 'Stark', 'email' => 'faceless@example.com'];
    $this->contactIDs[] = $this->callAPISuccess('Contact', 'create', $params)['id'];
    $this->contactIDs[] = $this->callAPISuccess('Contact', 'create', $params)['id'];
    $this->callAPISuccess('MailingProviderData', 'create', [
      'contact_identifier' => 'abc',
      'mailing_identifier' => 'xyz',
      'email' => 'faceless@example.com',
      'event_type' => 'Sent',
      'recipient_action_datetime' => 'now',
      'contact_id' => $this->contactIDs[1],
    ]);

    $this->callAPISuccess('Contact', 'merge', ['to_keep_id' => $this->contactIDs[0], 'to_remove_id' => $this->contactIDs[1]]);
    $this->callAPISuccessGetCount('MailingProviderData', ['contact_id' => $this->contactIDs[1]], 0);
    $this->callAPISuccessGetCount('MailingProviderData', ['contact_id' => $this->contactIDs[0]], 1);
  }
}

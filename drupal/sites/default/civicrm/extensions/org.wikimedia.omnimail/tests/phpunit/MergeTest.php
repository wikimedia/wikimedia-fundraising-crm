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
  public function testMerge(): void {
    $this->createAndMergeContactsWithMailingData();
    $this->callAPISuccessGetCount('MailingProviderData', ['contact_id' => $this->contactIDs[1]], 0);
    $this->callAPISuccessGetCount('MailingProviderData', ['contact_id' => $this->contactIDs[0]], 1);
  }

  /**
   * Test that when a merged contact is permanently deleted their data is copied across to the remaining contact.
   *
   * This is to ensure data not originally copied across IS brought over on permanent deletion.
   * The code is now fixed to copy on merge but older contacts have orphan data here.
   *
   * This means we can re-enable delete deleted contacts without losing more mailing provider data.
   *
   * @throws \CRM_Core_Exception
   */
  public function testPermanentDeleteHook(): void {
    $this->createAndMergeContactsWithMailingData();

    // Re-add some mailing provider data to the merged contact.
    $this->callAPISuccess('MailingProviderData', 'create', [
      'contact_identifier' => 'abc',
      'mailing_identifier' => 'xyz',
      'email' => 'faceless@example.com',
      'event_type' => 'OPEN',
      'recipient_action_datetime' => 'now',
      'contact_id' => $this->contactIDs[1],
    ]);

    // Delete the merged contact - both rows should be on the kept contact after the delete due to a pre hook.
    $this->callAPISuccess('Contact', 'delete', ['skip_undelete' => 1, 'id' => $this->contactIDs[1]]);
    unset($this->contactIDs[1]);
    $this->callAPISuccessGetCount('MailingProviderData', ['contact_id' => $this->contactIDs[0]], 2);
  }

  /**
   * @throws \CRM_Core_Exception
   */
  protected function createAndMergeContactsWithMailingData(): void {
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
  }

}

<?php

use CRM_Deduper_ExtensionUtil as E;
use Civi\Test\HeadlessInterface;
use Civi\Test\HookInterface;
use Civi\Test\TransactionalInterface;
use Civi\Test\Api3TestTrait;

require_once __DIR__ . '/../DedupeBaseTestClass.php';
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
class CRM_Deduper_Merge_RedoTest extends DedupeBaseTestClass {

  /**
   * Test redo-ing a merge.
   *
   * Steps
   *  - merge 2 contacts
   *  - add a contribution to the deleted by merge contact
   *  - call redo merge
   *  - check the contribution has been transferred
   *  - check the deleted contact is still deleted.
   * (note getsingle is an implict 'assert').
   */
  public function testRedoMerge() {
    $contactParams = [
      'contact_type' => 'Individual',
      'first_name' => 'Wonder',
      'last_name' => 'Woman',
      'email' => 'wonderwoman@example.org',
    ];
    $contactToBeMergedID = $this->ids['contact'][] = $this->callAPISuccess('Contact', 'create', $contactParams)['id'];
    $contactToKeepID = $this->ids['contact'][] = $this->callAPISuccess('Contact', 'create', $contactParams)['id'];
    $this->callAPISuccess('Contact', 'merge', ['to_keep_id' => $contactToKeepID, 'to_remove_id' => $contactToBeMergedID]);
    $this->callAPISuccess('Contribution', 'create', ['contact_id' => $contactToBeMergedID, 'total_amount' => 10, 'financial_type_id' => 'Donation']);

    $this->callAPISuccess('Merge', 'redo', ['contact_id' => $contactToBeMergedID]);
    $this->callAPISuccessGetSingle('Contribution', ['contact_id' => $contactToKeepID]);
    $this->callAPISuccessGetSingle('Contact', ['is_deleted' => 1, 'id' => $contactToBeMergedID]);
  }

}

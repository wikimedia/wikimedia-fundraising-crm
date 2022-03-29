<?php

require_once __DIR__ . '/OmnimailBaseTestClass.php';

use Civi\Api4\Group;
use Civi\Api4\Omnicontact;
use Civi\Api4\OmnigroupMember;

/**
 * Test Omnicontact create method.
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
class OmnicontactCreateTest extends OmnimailBaseTestClass {

  /**
   * Post test cleanup.
   *
   * @throws \API_Exception
   * @throws \CRM_Core_Exception
   */
  public function tearDown(): void {
    Group::delete(FALSE)->addWhere('name', '=', 'test_create_group')->execute();
    parent::tearDown();
  }

  /**
   * Example: the groupMember load fn works.
   *
   * @throws \API_Exception
   */
  public function testAddToGroup(): void {
    $this->getMockRequest([file_get_contents(__DIR__ . '/Responses/AddRecipient.txt')]);
    $group = Group::create(FALSE)->setValues([
      'name' => 'test_create_group',
      'title' => 'Test group create',
      'Group_Metadata.remote_group_identifier' => 42192504,
    ])->execute()->first();
    $result = Omnicontact::create(FALSE)
      ->setGroupID([$group['id']])
      ->setDatabaseID(1234)
      ->setClient($this->getGuzzleClient())
      ->setEmail('jenny@example.com')
      ->setValues([
        'last_name' => 'Jenny',
        'first_name' => 'Lee',
      ])
      ->execute()->first();
    $this->assertEquals(569624660942, $result['contact_identifier']);
    $this->assertEquals(trim(file_get_contents(__DIR__ . '/Requests/AddRecipient.txt')), $this->getRequestBodies()[0]);

  }

}

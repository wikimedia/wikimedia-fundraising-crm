<?php

use Civi\Api4\Group;
use Civi\Api4\Omnigroup;
use GuzzleHttp\Client;

require_once __DIR__ . '/OmnimailBaseTestClass.php';

/**
 * Test Omnigroup create method.
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
class OmnigroupCreateTest extends OmnimailBaseTestClass {

  /**
   * Post test cleanup.
   *
   * @throws \CRM_Core_Exception
   */
  public function tearDown(): void {
    Group::delete(FALSE)->addWhere('name', '=', 'Omnigroup test')->execute();
    parent::tearDown();
  }

  /**
   * Example: the groupMember load fn works.
   *
   * @throws \CRM_Core_Exception
   */
  public function testOmnigroupCreate(): void {
    $this->getMockRequest([file_get_contents(__DIR__ . '/Responses/CreateContactListResponse.txt')]);
    $groupID = Group::create(FALSE)->setValues([
      'name' => 'Omnigroup test',
      'title' => 'Omnigroup test',
    ])->execute()->first()['id'];
    $this->assertEquals([
        'list_id' => 42133432,
        'parent_id' => 9574333,
        'Group_Metadata.remote_group_identifier' => 42133432,
        'name' => 'Omnigroup test',
      ],
      Omnigroup::create(FALSE)
      ->setGroupID($groupID)
      ->setClient($this->getGuzzleClient())
      ->setDatabaseID(12345678)->execute()->first());
    $this->assertEquals(trim(file_get_contents(__DIR__ . '/Requests/CreateContactList.txt')), $this->getRequestBodies()[0]);
  }

}

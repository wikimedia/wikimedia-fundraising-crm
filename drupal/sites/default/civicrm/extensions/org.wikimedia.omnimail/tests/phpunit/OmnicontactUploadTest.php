<?php

namespace phpunit;

use Civi\Api4\Group;
use Civi\Api4\Omnicontact;
use GuzzleHttp\Client;
use OmnimailBaseTestClass;

require_once __DIR__ . '/OmnimailBaseTestClass.php';

/**
 * Test Omnigroup create method.
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
class OmnicontactUploadTest extends OmnimailBaseTestClass {

  /**
   * Example: the groupMember load fn works.
   *
   * @throws \CRM_Core_Exception
   */
  public function testUpload(): void {
    $client = $this->getMockRequest([file_get_contents(__DIR__ . '/Responses/ImportListResponse.txt')]);
    Omnicontact::upload(FALSE)
      ->setClient($client)
      // For the test, do not do the sftp as it is not mocked.
      ->setIsAlreadyUploaded(TRUE)
      ->setCsvFile(__DIR__ . '/ImportFiles/example.csv')
      ->setMappingFile(__DIR__ . '/ImportFiles/example.xml')
      // ->setDatabaseID(12345678)
      ->execute()->first();
    $this->assertEquals(trim(file_get_contents(__DIR__ . '/Requests/ImportListRequest.txt')), $this->getRequestBodies()[0]);
  }

}

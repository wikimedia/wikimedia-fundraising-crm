<?php

use Civi\Api4\Omnicontact;

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
class OmnicontactGetTest extends OmnimailBaseTestClass {

  /**
   * Test retrieving a contact from the remote provider.
   *
   * @throws \CRM_Core_Exception
   */
  public function testGetRecipient(): void {
    $this->getMockRequest([
      file_get_contents(__DIR__ . '/Responses/SelectRecipientData.txt'),
      file_get_contents(__DIR__ . '/Responses/AuthenticateRestResponse.txt'),
      file_get_contents(__DIR__ . '/Responses/ConsentInformationResponse.txt'),
    ]);

    $result = Omnicontact::get(FALSE)
      ->setClient($this->getGuzzleClient())
      // This would be picked up from settings if not set here.
      ->setDatabaseID(345)
      ->setEmail('jenny@example.com')
      ->execute()->first();
    $this->assertEquals(trim(file_get_contents(__DIR__ . '/Requests/SelectRecipientData.txt')), $this->getRequestBodies()[0]);
    $this->assertEquals('jenny@example.com', $result['email']);
    $this->assertEquals('Jenny', $result['firstname']);
    $this->assertEquals('Lee', $result['lastname']);
    $this->assertEquals('2022-03-02 06:08:00', $result['opt_in_date']);
    $this->assertEquals('2022-03-03 02:50:00', $result['last_modified_date']);
    $this->assertEquals(123456, $result['contact_identifier']);
    $this->assertEquals('https://cloud.goacoustic.com/campaign-automation/Data/Databases?cuiOverrideSrc=https%253A%252F%252Fcampaign-us-4.goacoustic.com%252FsearchRecipient.do%253FisShellUser%253D1%2526action%253Dedit%2526listId%253D9644238%2526recipientId%253D123456&listId=345',$result['url'] );
  }

}

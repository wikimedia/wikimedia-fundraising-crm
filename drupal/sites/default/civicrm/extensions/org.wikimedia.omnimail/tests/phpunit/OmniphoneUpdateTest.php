<?php

namespace phpunit;

use Civi\Api4\Contact;
use Civi\Api4\Omniphone;
use Civi\Api4\Phone;
use Civi\Api4\PhoneConsent;
use OmnimailBaseTestClass;

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
class OmniphoneUpdateTest extends OmnimailBaseTestClass {

  /**
   * Test retrieving a contact from the remote provider.
   *
   * @throws \CRM_Core_Exception
   */
  public function testUpdatePhone(): void {
    Contact::create(FALSE)
      ->setValues([
        'contact_type' => 'Individual',
        'first_name' => 'John',
        'last_name' => 'Mouse',
        'email_primary.email' => 'john@mouse.com',
        'phone_primary.phone' => \CRM_Omnimail_Omnicontact::DUMMY_PHONE,
        'phone_primary.phone_data.recipient_id' => 12345,
      ])
      ->execute();
    $this->getMockRequest([
      file_get_contents(__DIR__ . '/Responses/SelectRecipientData.txt'),
      file_get_contents(__DIR__ . '/Responses/AuthenticateRestResponse.txt'),
      file_get_contents(__DIR__ . '/Responses/ConsentInformationResponse.txt'),
    ]);

    $result = Omniphone::batchUpdate(FALSE)
      ->setClient($this->getGuzzleClient())
      // This would be picked up from settings if not set here.
      ->setDatabaseID(345)
      ->execute()->first();
    $this->assertEquals(trim(file_get_contents(__DIR__ . '/Requests/SelectRecipientDataByRecipientID.txt')), $this->getRequestBodies()[0]);
    $consent = PhoneConsent::get(FALSE)
      ->addWhere('phone_number', '=', 9099909021)
      ->execute()->first();
    $this->assertEquals('Sms Consent Kafka Streams', $consent['consent_source']);
    $this->assertEquals(12345, $consent['master_recipient_id']);
    $this->assertEquals(9099909021, $consent['phone_number']);
    $this->assertTrue($consent['opted_in']);
    $this->assertEquals(1, $consent['country_code']);
    $this->assertEquals('2024-11-27 00:08:59', $consent['consent_date']);

    $phone = Phone::get(FALSE)
      ->addWhere('phone', '=', 9099909021)
      ->addSelect('contact_id.email_primary.email')
      ->execute()->first();
    $this->assertEquals('john@mouse.com', $phone['contact_id.email_primary.email']);
  }

}

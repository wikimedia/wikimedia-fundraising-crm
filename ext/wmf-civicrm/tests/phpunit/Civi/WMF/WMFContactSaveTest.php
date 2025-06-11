<?php

namespace Civi\WMF;

use Civi\Api4\Contact;
use Civi\Api4\WMFContact;
use Civi\WMFQueueMessage\RecurDonationMessage;
use Civi\WMFQueueMessage\RecurringModifyMessage;
use PHPUnit\Framework\TestCase;

/**
 * Contact Save tests for WMF user cases.
 *
 */
class WMFContactSaveTest extends TestCase {

  /**
   * @var array
   */
  protected array $ids = [];

  /**
   * Clean up after test.
   *
   * @throws \Civi\Core\Exception\DBQueryException
   * @throws \CRM_Core_Exception
   */
  protected function tearDown(): void {
    if (!empty($this->ids['Contact'])) {
      Contact::delete(FALSE)
        ->addWhere('id', 'IN', $this->ids['Contact'])
        ->setUseTrash(FALSE)->execute();
    }
    Contact::delete(FALSE)
      ->addWhere('last_name', '=', 'Mouse')
      ->setUseTrash(FALSE)->execute();
    parent::tearDown();
    unset(\Civi::$statics['wmf_contact']);
  }

  /**
   * @throws \CRM_Core_Exception|\Random\RandomException
   */
  public function testExternalIdentifierFundraiseupIdUpdate(): void {
    $fundraiseup_id = random_int(10000, 11200);
    $initialDetails = new RecurringModifyMessage([
      'first_name' => 'Sarah',
      'last_name' => 'Mouse',
      'nick_name' => '',
      'email' => 'sarah@bb.org',
      'gateway' => 'adyen',
      'payment_method' => 'cc',
      'external_identifier' => '',
      'country' => 'US',
      'street_address' => '',
      'city' => '',
      'street_number' => '',
      'postal_code' => '',
      'state_province' => '',
    ]);
    $oldContactId = WMFContact::save(FALSE)->setMessage($initialDetails->normalize())->execute()->first()['id'];

    $newDetails = new RecurringModifyMessage(array_merge($initialDetails->normalize(), [
      'id' => $oldContactId,
      'contact_id' => $oldContactId,
      'gateway' => 'fundraiseup',
      'external_identifier' => $fundraiseup_id,
    ]));

    WMFContact::save(FALSE)->setMessage($newDetails->normalize())->execute();
    $updatedContact = Contact::get(FALSE)
      ->addSelect('External_Identifiers.fundraiseup_id', 'email_primary.email')
      ->addWhere('id', '=', $oldContactId)
      ->execute()->first();

    $this->assertNotNull($updatedContact);
    $this->assertEquals('sarah@bb.org', $updatedContact['email_primary.email']);
    $this->assertEquals($fundraiseup_id, $updatedContact['External_Identifiers.fundraiseup_id']);
  }

  /**
   * @throws \CRM_Core_Exception
   * @throws \Civi\WMFException\WMFException
   */
  public function testExternalIdentifierIdDedupe(): void {
    $new_email = 'anothertestemail@em.org';
    $new_first_name = 'One';
    $initialDetails = new RecurDonationMessage([
      'first_name' => 'Zero',
      'last_name' => 'Mouse',
      'nick_name' => 'Nick',
      'email' => 'testemail@em.org',
      'gateway' => 'fundraiseup',
      'external_identifier' => 6666666666,
      'payment_method' => 'cc',
      'country' => 'US',
      'street_address' => '',
      'city' => '',
      'street_number' => '',
      'postal_code' => '',
      'state_province' => '',
    ]);

    $newDetails = new RecurDonationMessage(array_merge($initialDetails->normalize(), [
      'first_name' => $new_first_name,
      'email' => $new_email,
    ]));

    WMFContact::save(FALSE)->setMessage($initialDetails->normalize())->execute();
    WMFContact::save(FALSE)->setMessage($newDetails->normalize())->execute();
    $contact = Contact::get(FALSE)
      ->addSelect('first_name', 'External_Identifiers.fundraiseup_id', 'email_primary.email')
      ->addWhere('External_Identifiers.fundraiseup_id', '=', 6666666666)
      ->execute();
    $this->ids['Contact'][] = $contact[0]['id'];

    $this->assertCount(1, $contact);
    $this->assertEquals($new_email, $contact[0]['email_primary.email']);
    $this->assertEquals($new_first_name, $contact[0]['first_name']);
  }

}

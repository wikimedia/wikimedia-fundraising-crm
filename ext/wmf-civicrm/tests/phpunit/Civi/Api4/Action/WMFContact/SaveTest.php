<?php

namespace Civi\Api4\WMFContact;

use Civi\Api4\Contact;
use Civi\Api4\WMFContact;
use Civi\WMFQueueMessage\RecurDonationMessage;
use Civi\WMFQueueMessage\RecurringModifyMessage;
use PHPUnit\Framework\TestCase;

/**
 * Contact Save tests for WMF user cases.
 * @group epcV4
 * @covers \Civi\Api4\Action\WMFContact\Save
 */
class SaveTest extends TestCase {

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

  public function testVenmoDiffNameDedupe(): void {
    // Create a contact with email
    $donationMessage = new RecurDonationMessage([
      'first_name' => 'Venmo',
      'last_name' => 'Test',
      'nick_name' => '',
      'email' => 'aaa@aa.com',
      'gateway' => 'braintree',
      'external_identifier' => '@venmojoe123',
      'payment_method' => 'venmo',
      'country' => 'US',
      'street_address' => '',
      'city' => '',
      'street_number' => '',
      'postal_code' => '',
      'state_province' => '',
    ]);
    WMFContact::save(FALSE)->setMessage($donationMessage->normalize())->execute();
    // Verify this contact is unique
    $contacts = Contact::get(FALSE)
      ->addWhere('email_primary.email', '=', 'aaa@aa.com')
      ->addSelect('id')
      ->execute();
    $this->assertCount(1, $contacts);
    // Consume a donation message with the same email
    $anotherDonationMessage = new RecurDonationMessage([
      'first_name' => 'diff-firstname',
      'last_name' => 'Test',
      'nick_name' => '',
      'email' => 'aaa@aa.com',
      'gateway' => 'braintree',
      'external_identifier' => '@venmojoe123',
      'payment_method' => 'venmo',
      'country' => 'US',
      'street_address' => '',
      'city' => '',
      'street_number' => '',
      'postal_code' => '',
      'state_province' => '',
    ]);
    WMFContact::save(FALSE)->setMessage($anotherDonationMessage->normalize())->execute();
    $afterContacts = Contact::get(FALSE)
      ->addSelect('id', 'External_Identifiers.venmo_user_name', 'email_primary.email')
      ->addWhere('External_Identifiers.venmo_user_name', '=', '@venmojoe123')
      ->execute();
    // Verify that no new contact was created since the email matches
    $this->assertCount(1, $afterContacts);
    $this->assertEquals('@venmojoe123', $afterContacts[0]['External_Identifiers.venmo_user_name']);
    $this->assertEquals($contacts[0]['id'], $afterContacts[0]['id']);
  }
}

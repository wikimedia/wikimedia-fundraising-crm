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
  public function testVenmoDifferentExternalButPhoneMatched(): void {
    // Create a contact with phone
    $donationMessage = new RecurDonationMessage([
      'first_name' => 'Venmo',
      'last_name' => 'Test',
      'nick_name' => '',
      'email' => '123@aa.com',
      'gateway' => 'braintree',
      'external_identifier' => '@venmojoe_123',
      'payment_method' => 'venmo',
      'phone' => '1234567890',
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
      ->addWhere('phone_primary.phone', '=', '1234567890')
      ->addSelect('id')
      ->execute();
    $this->assertCount(1, $contacts);
    // Consume a donation message with the same phone but different external identifier
    $anotherDonationMessage = new RecurDonationMessage([
      'first_name' => 'diff-firstname',
      'last_name' => 'Test',
      'nick_name' => '',
      'email' => 'diff@aa.com',
      'gateway' => 'braintree',
      'external_identifier' => '@venmojoe_diff',
      'payment_method' => 'venmo',
      'phone' => '1234567890',
      'country' => 'US',
      'street_address' => '',
      'city' => '',
      'street_number' => '',
      'postal_code' => '',
      'state_province' => '',
    ]);
    WMFContact::save(FALSE)->setMessage($anotherDonationMessage->normalize())->execute();
    $afterContacts = Contact::get(FALSE)
      ->addSelect('id', 'External_Identifiers.venmo_user_name', 'email_primary.email', 'phone_primary.phone')
      ->addWhere('phone_primary.phone', '=', '1234567890')
      ->execute();
    // Verify that no new contact was created since the phone matches
    $this->assertCount(1, $afterContacts);
    $this->assertEquals('1234567890', $afterContacts[0]['phone_primary.phone']);
    $this->assertEquals('diff@aa.com', $afterContacts[0]['email_primary.email']);
    $this->assertEquals('@venmojoe_diff', $afterContacts[0]['External_Identifiers.venmo_user_name']);
    $this->assertEquals($contacts[0]['id'], $afterContacts[0]['id']);
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

  function testMatchExactEmailWithSameNameWhileLowConfidence(): void
  {
    // Create a contact with email with untrusted method like google
    $googleDonationMessage = new RecurDonationMessage([
      'first_name' => 'Google',
      'last_name' => 'Mouse',
      'email' => 'namenotmatch@test.com',
      'gateway' => 'gravy',
      'payment_method' => 'google',
      'country' => 'US'
    ]);
    WMFContact::save(FALSE)->setMessage($googleDonationMessage->normalize())->execute();

    // Verify this contact is unique
    $contactGoogle = Contact::get(FALSE)
      ->addWhere('email_primary.email', '=', 'namenotmatch@test.com')
      ->addSelect('id')
      ->execute();
    $this->assertCount(1, $contactGoogle);
    $this->ids['Contact'][] = $contactGoogle[0]['id'];
    // assign google as location type, this is not longer needed after we assign location type, but need now
    \Civi\Api4\Email::update(FALSE)
      ->addWhere('contact_id', '=', $contactGoogle[0]['id'])
      ->setValues(['location_type_id:name' => 'google'] )
      ->execute();
    // Consume a new donation message with diff email also untrusted name source
    $appleDonationMessage = new RecurDonationMessage([
      'first_name' => 'Apple',
      'last_name' => 'Mouse',
      'email' => 'newEmail@test.com',
      'gateway' => 'gravy',
      'payment_method' => 'apple',
      'country' => 'US'
    ]);
    WMFContact::save(FALSE)->setMessage($appleDonationMessage->normalize())->execute();
    // Verify this contact is unique
    $contactApple = Contact::get(FALSE)
      ->addWhere('email_primary.email', '=', 'newEmail@test.com')
      ->addSelect('id')
      ->execute();
    $this->assertCount(1, $contactApple);
    $this->ids['Contact'][] = $contactApple[0]['id'];
    // assign apple as location type, this is not longer needed after we assign location type, but need now
    \Civi\Api4\Email::update(FALSE)
      ->addWhere('contact_id', '=', $contactApple[0]['id'])
      ->setValues(['location_type_id:name' => 'apple'] )
      ->execute();
    // google created earlier, so cid smaller/older than apple
    $this->assertTrue($contactGoogle[0]['id'] < $contactApple[0]['id']);
    // when new donation comes with same email as google, should match even no name match since the google is untrusted source
    $matchGoogleDonation = new RecurDonationMessage([
      'first_name' => 'diff-firstname',
      'last_name' => 'Mouse',
      'email' => 'namenotmatch@test.com',
      'gateway' => 'gravy',
      'payment_method' => 'cc',
      'country' => 'US'
    ]);
    WMFContact::save(FALSE)->setMessage($matchGoogleDonation->normalize())->execute();
    // the above should match google since same email, when no name match
    $afterMatchGoogle = Contact::get(FALSE)
      ->addWhere('email_primary.email', '=', 'namenotmatch@test.com')
      ->addSelect('id', 'first_name')
      ->execute();
    $this->assertCount(1, $afterMatchGoogle);
    $this->assertEquals($contactGoogle[0]['id'], $afterMatchGoogle[0]['id']);
    $this->assertEquals('diff-firstname', $afterMatchGoogle[0]['first_name']); // updated first name to trusted source's
    // now google contact cid has firstname diff-firstname and email namenotmatch@test.com
    // let's update the apple contact with the same email as google for future match pair
    Contact::update(FALSE)
      ->addWhere('id', '=', $contactApple[0]['id'])
      ->setValues(['email_primary.email' => 'namenotmatch@test.com'] )
      ->execute();
    // Consume a donation message with the same email and same name as apple even the google one has older cid, but diff name is lower priority for all match
    $paypalDonation = new RecurDonationMessage([
      'first_name' => 'Apple',
      'last_name' => 'Mouse',
      'email' => 'namenotmatch@test.com',
      'gateway' => 'gravy',
      'payment_method' => 'paypal',
      'country' => 'US'
    ]);
    WMFContact::save(FALSE)->setMessage($paypalDonation->normalize())->execute();
    // the above should match apple since same email and same name even the apple is newer than google but google has diff name
    $afterMatchPaypal = Contact::get(FALSE)
      ->addWhere('email_primary.email', '=', 'namenotmatch@test.com')
      ->addSelect('id', 'first_name')
      ->setOrderBy(['id' => 'ASC'])
      ->execute();
    $this->assertCount(2, $afterMatchPaypal);
    $this->assertEquals($contactApple[0]['id'], $afterMatchPaypal[1]['id']); // later one have the cid matched for this new paypal donation
    $this->assertEquals('diff-firstname', $afterMatchPaypal[0]['first_name']);
  }
}

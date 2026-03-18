<?php

namespace Civi\Api4\WMFContact;

use Civi\Api4\Contact;
use Civi\Api4\Email;
use Civi\Api4\WMFContact;
use Civi\WMFQueueMessage\RecurDonationMessage;
use Civi\WMFQueueMessage\DonationMessage;
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

  public function testNewEmailWithSamePrimaryEmailNotOverwriteLocationType(): void {
    $initDonationMessage = new DonationMessage([
      'first_name' => 'CC donation',
      'last_name' => 'Mouse',
      'email' => 'same_as_new_apple@email.com',
      'gateway' => 'gravy',
      'payment_method' => 'cc',
      'country' => 'US'
    ]);
    WMFContact::save(FALSE)->setMessage($initDonationMessage->normalize())->execute();
    $currentContact = Contact::get(FALSE)
      ->addWhere('email_primary.email', '=', 'same_as_new_apple@email.com')
      ->addSelect('id', 'email_primary.location_type_id:name')
      ->execute()->first();
    $this->assertEquals('Home', $currentContact['email_primary.location_type_id:name']);
    $this->ids['Contact'][] = $currentContact['id'];
    $newDonationMessage = new DonationMessage([
      'first_name' => 'Apple',
      'last_name' => 'Mouse',
      'email' => 'same_as_new_apple@email.com',
      'gateway' => 'adyen',
      'payment_method' => 'apple',
      'country' => 'US'
    ]);
    WMFContact::save(FALSE)->setMessage($newDonationMessage->normalize())->execute();
    $emails = Email::get(FALSE)
      ->addWhere('contact_id', '=', $currentContact['id'])
      ->addSelect('email', 'location_type_id:name', 'is_primary')
      ->execute()->indexBy('location_type_id:name');
    $this->assertCount(2, $emails);
    $this->assertEquals('same_as_new_apple@email.com', $emails['Home']['email']);
    $this->assertEquals(1, $emails['Home']['is_primary']);
    $this->assertEquals('same_as_new_apple@email.com', $emails['apple']['email']);
  }

  public function testNewEmailWithSamePrimaryEmailDemoteOldUntrustLocationType(): void {
    $initDonationMessage = new DonationMessage([
      'first_name' => 'google donation',
      'last_name' => 'Mouse',
      'email' => 'same_google_as_cc@email.com',
      'gateway' => 'gravy',
      'payment_method' => 'google',
      'country' => 'US'
    ]);
    WMFContact::save(FALSE)->setMessage($initDonationMessage->normalize())->execute();
    $currentContact = Contact::get(FALSE)
      ->addWhere('email_primary.email', '=', 'same_google_as_cc@email.com')
      ->addSelect('id', 'email_primary.location_type_id:name')
      ->execute()->first();
    $this->assertEquals('google', $currentContact['email_primary.location_type_id:name']);
    $this->ids['Contact'][] = $currentContact['id'];
    $newDonationMessage = new DonationMessage([
      'first_name' => 'donation',
      'last_name' => 'Mouse',
      'email' => 'same_google_as_cc@email.com',
      'gateway' => 'adyen',
      'payment_method' => 'cc',
      'country' => 'US',
    ]);
    WMFContact::save(FALSE)->setMessage($newDonationMessage->normalize())->execute();
    $emails = Email::get(FALSE)
      ->addWhere('contact_id', '=', $currentContact['id'])
      ->addSelect('email', 'location_type_id:name', 'is_primary')
      ->execute()->indexBy('location_type_id:name');
    $this->assertCount(2, $emails);
    $this->assertEquals('same_google_as_cc@email.com', $emails['Home']['email']);
    $this->assertEquals('same_google_as_cc@email.com', $emails['google']['email']);
    $this->assertEquals(0, $emails['google']['is_primary']);
  }

  public function testAddNewEmailTypeWithDifferentEmailThanPrimary(): void {
    $initDonationMessage = new DonationMessage([
      'first_name' => 'Email',
      'last_name' => 'Mouse',
      'email' => 'cc_donation@email.com',
      'gateway' => 'adyen',
      'payment_method' => 'cc',
      'country' => 'US',
    ]);
    WMFContact::save(FALSE)->setMessage($initDonationMessage->normalize())->execute();
    $currentContact = Contact::get(FALSE)
      ->addWhere('email_primary.email', '=', 'cc_donation@email.com')
      ->addSelect('id', 'email_primary.email', 'email_primary.location_type_id:name')
      ->execute()->first();
    $this->assertEquals('Home', $currentContact['email_primary.location_type_id:name']);
    $this->assertEquals('cc_donation@email.com', $currentContact['email_primary.email']);
    // new apple pay coming, instead of replace the primary email, we will add a new email with location type as 'apple' and the old email will still be the primary
    $appleDonationMessage = new DonationMessage([
      'first_name' => 'Apple',
      'last_name' => 'Mouse',
      'email' => 'apple@donation.test',
      'gateway' => 'adyen',
      'payment_method' => 'apple',
      'country' => 'US',
      'contact_id' => $currentContact['id'],
    ]);
    $this->ids['Contact'][] = $currentContact['id'];
    WMFContact::save(FALSE)->setMessage($appleDonationMessage->normalize())->execute();
    // new paypal coming, instead of replace the primary email, we will add a new email with location type as 'paypal' and the old email will still be the primary
    $paypalDonationMessage = new DonationMessage([
      'first_name' => 'Paypal',
      'last_name' => 'Mouse',
      'email' => 'paypal@donation.test',
      'gateway' => 'gravy',
      'payment_method' => 'paypal',
      'country' => 'US',
      'external_identifier' => 'uniquePaypalExtIdentifier',
      'contact_id' => $currentContact['id'],
    ]);
    WMFContact::save(FALSE)->setMessage($paypalDonationMessage->normalize())->execute();
    // new paypal coming, since already have email_paypal, update to new email with same location type as 'paypal', also map with external identifier not contact id
    $anotherPaypalDonationMessage = new DonationMessage([
      'first_name' => 'Paypal',
      'last_name' => 'Mouse',
      'email' => 'paypal+update@donation.test',
      'gateway' => 'paypal_ec',
      'payment_method' => 'paypal',
      'external_identifier' => 'uniquePaypalExtIdentifier',
      'country' => 'US',
    ]);
    WMFContact::save(FALSE)->setMessage($anotherPaypalDonationMessage->normalize())->execute();
    $emails = Email::get(FALSE)
      ->addSelect('email', 'location_type_id:name', 'is_primary')
      ->addWhere('contact_id' , '=', $currentContact['id'])
      ->execute()->indexBy('location_type_id:name');
    $this->assertEquals(3, count($emails));
    $this->assertEquals(1, $emails['Home']['is_primary']);
    $this->assertEquals('cc_donation@email.com', $emails['Home']['email']);
    $this->assertEquals('apple@donation.test', $emails['apple']['email']);
    $this->assertEquals('paypal+update@donation.test', $emails['paypal']['email']);
  }

  public function testCreateBrandNewContactWithEmailLocationType(): void {
    $donationMessage = new RecurDonationMessage([
      'first_name' => 'UniqueVenmoTest',
      'last_name' => 'Mouse',
      'email' => 'unique_email_w_location_type@test.com',
      'gateway' => 'gravy',
      'external_identifier' => 'venmouniqueEmail',
      'payment_method' => 'venmo',
      'country' => 'US',
      'phone' => '15543521234',
    ]);
    WMFContact::save(FALSE)->setMessage($donationMessage->normalize())->execute();
    $contacts = Contact::get(FALSE)
      ->addWhere('email_primary.email', '=', 'unique_email_w_location_type@test.com')
      ->addSelect('id', 'External_Identifiers.venmo_user_name', 'email_primary.email', 'email_primary.location_type_id:name')
      ->execute();
    $this->assertCount(1, $contacts);
    $this->ids['Contact'][] = $contacts[0]['id'];
    $this->assertEquals('@venmouniqueEmail', $contacts[0]['External_Identifiers.venmo_user_name']);
    $this->assertEquals('venmo', $contacts[0]['email_primary.location_type_id:name']);
  }

  public function testCasesPrimaryEmailGetUpdated(): void {
    $initThirdPartyDonation = new RecurDonationMessage([
      'first_name' => 'init_venmo',
      'last_name' => 'Mouse',
      'email' => 'init_venmo@mouse.org',
      'gateway' => 'braintree',
      'external_identifier' => 'xxxx-xxx',
      'payment_method' => 'venmo',
      'country' => 'US',
      'phone' => '112233445566'
    ]);
    WMFContact::save(FALSE)->setMessage($initThirdPartyDonation->normalize())->execute();
    $contacts = Contact::get(FALSE)
      ->addWhere('email_primary.email', '=', 'init_venmo@mouse.org')
      ->addSelect('id', 'External_Identifiers.venmo_user_name', 'email_primary.location_type_id:name',
        'phone_primary.phone', 'phone_primary.phone_data.phone_source')
      ->execute();
    $this->assertCount(1, $contacts);
    $this->ids['Contact'][] = $contacts[0]['id'];
    $this->assertEquals('@xxxx-xxx', $contacts[0]['External_Identifiers.venmo_user_name']);
    $this->assertEquals('venmo', $contacts[0]['email_primary.location_type_id:name']);
    $this->assertEquals('112233445566', $contacts[0]['phone_primary.phone']);
    $this->assertEquals('Venmo', $contacts[0]['phone_primary.phone_data.phone_source']);
    // match same cid based on same venmo phone, since same venmo user,
    $secondThirdPartyDonation = new RecurDonationMessage([
      'first_name' => 'UniqueVenmoTest',
      'last_name' => 'Mouse',
      'email' => 'current_primary_email_unique+update@test.com',
      'gateway' => 'gravy',
      'external_identifier' => 'xxxx-xxx-updated',
      'payment_method' => 'venmo',
      'country' => 'US',
      'phone' => '112233445566',
    ]);
    WMFContact::save(FALSE)->setMessage($secondThirdPartyDonation->normalize())->execute();
    $updatedContacts = Contact::get(FALSE)
      ->addWhere('id', '=', $contacts[0]['id'])
      ->addSelect('id', 'External_Identifiers.venmo_user_name', 'email_primary.email', 'email_primary.location_type_id:name')
      ->execute();
    $this->assertCount(1, $updatedContacts);
    // both venmo username and venmo email need to be updated to the new one since venmo phone match,
    // so even the new one is thirdparty, still update primary email since location type match
    $this->assertEquals('@xxxx-xxx-updated', $updatedContacts[0]['External_Identifiers.venmo_user_name']);
    $this->assertEquals('venmo', $updatedContacts[0]['email_primary.location_type_id:name']);
    $this->assertEquals('current_primary_email_unique+update@test.com', $updatedContacts[0]['email_primary.email']);
    $ccDonation = new DonationMessage([
      'first_name' => 'CC',
      'last_name' => 'Mouse',
      'email' => 'new_should_updated_primary@test.com',
      'gateway' => 'adyen',
      'payment_method' => 'cc',
      'country' => 'US',
      'contact_id' => $contacts[0]['id'],
    ]);
    WMFContact::save(FALSE)->setMessage($ccDonation->normalize())->execute();
    $emails = Email::get(FALSE)
      ->addWhere('contact_id', '=', $contacts[0]['id'])
      ->addSelect('email', 'location_type_id:name')
      ->execute()->indexBy('location_type_id:name');
    $this->assertCount(2, $emails);
    $this->assertEquals('new_should_updated_primary@test.com',  $emails['Home']['email']);
    $this->assertEquals('current_primary_email_unique+update@test.com', $emails['venmo']['email']);
  }

  public function testBrandNewPaypalDonorWithPaypalEmailLocation(): void {
    $donationMessage = new RecurDonationMessage([
      'first_name' => 'Paypal',
      'last_name' => 'Mouse',
      'email' => 'paypal@test.com',
      'gateway' => 'paypal_ec',
      'external_identifier' => 'paypal_123',
      'payment_method' => 'paypal',
      'country' => 'US',
      'phone' => '5555555555',
    ]);
    WMFContact::save(FALSE)->setMessage($donationMessage->normalize())->execute();
    $contacts = Contact::get(FALSE)
      ->addWhere('email_primary.email', '=', 'paypal@test.com')
      ->addSelect('id', 'External_Identifiers.paypal_payer_id', 'phone_primary.phone', 'phone_primary.phone_data.phone_source', 'email_primary.location_type_id:name')
      ->execute();
    $this->ids['Contact'][] = $contacts[0]['id'];
    $this->assertCount(1, $contacts);
    $this->assertEquals('paypal_123', $contacts[0]['External_Identifiers.paypal_payer_id']);
    $this->assertEquals('5555555555', $contacts[0]['phone_primary.phone']);
    $this->assertEquals('Paypal', $contacts[0]['phone_primary.phone_data.phone_source']);
    $this->assertEquals('paypal', $contacts[0]['email_primary.location_type_id:name']);
  }

  public function testVenmoDiffNameDedupe(): void {
    // Create a contact with email
    $donationMessage = new RecurDonationMessage([
      'first_name' => 'Venmo',
      'last_name' => 'Mouse',
      'email' => 'aaa@aa.com',
      'gateway' => 'braintree',
      'external_identifier' => '@venmojoe123',
      'phone' => '2234567890',
      'payment_method' => 'venmo',
      'country' => 'US',
    ]);
    WMFContact::save(FALSE)->setMessage($donationMessage->normalize())->execute();
    // Verify this contact is unique
    $contacts = Contact::get(FALSE)
      ->addWhere('email_primary.email', '=', 'aaa@aa.com')
      ->addSelect('id')
      ->execute();
    $this->ids['Contact'][] = $contacts[0]['id'];
    $this->assertCount(1, $contacts);
    // Consume a donation message with the same email
    $anotherDonationMessage = new RecurDonationMessage([
      'first_name' => 'diff-firstname',
      'last_name' => 'Mouse',
      'email' => 'aaaa@aa.com',
      'gateway' => 'braintree',
      'phone' => '2234567890',
      'external_identifier' => '@venmojoe-123',
      'payment_method' => 'venmo',
      'country' => 'US',
    ]);
    WMFContact::save(FALSE)->setMessage($anotherDonationMessage->normalize())->execute();
    $afterContacts = Contact::get(FALSE)
      ->addSelect('id', 'External_Identifiers.venmo_user_name', 'email_primary.email', 'phone_primary.phone', 'phone_primary.phone_data.phone_source', 'email_primary.location_type_id:name')
      ->addWhere('External_Identifiers.venmo_user_name', '=', '@venmojoe-123')
      ->execute();
    // Verify that no new contact was created since the email matches
    $this->assertCount(1, $afterContacts);
    $this->assertEquals('@venmojoe-123', $afterContacts[0]['External_Identifiers.venmo_user_name']);
    $this->assertEquals('Venmo', $afterContacts[0]['phone_primary.phone_data.phone_source']);
    $this->assertEquals('aaaa@aa.com', $afterContacts[0]['email_primary.email']);
    $this->assertEquals('venmo', $afterContacts[0]['email_primary.location_type_id:name']);
    $this->assertEquals('2234567890', $afterContacts[0]['phone_primary.phone']);
    $this->assertEquals($contacts[0]['id'], $afterContacts[0]['id']);
  }

  public function testLowConfidenceNameDonationContactMatch(): void {
    // Create a contact with email with untrusted method like venmo
    $donationMessage = new RecurDonationMessage([
      'first_name' => 'Venmo',
      'last_name' => 'Mouse',
      'email' => 'aaa@aa.com',
      'gateway' => 'braintree',
      'external_identifier' => '@venmojoe123',
      'payment_method' => 'venmo',
      'country' => 'US'
    ]);
    WMFContact::save(FALSE)->setMessage($donationMessage->normalize())->execute();
    // Verify this contact is unique
    $contacts = Contact::get(FALSE)
      ->addWhere('email_primary.email', '=', 'aaa@aa.com')
      ->addSelect('id')
      ->execute();
    $this->assertCount(1, $contacts);
    $this->ids['Contact'][] = $contacts[0]['id'];
    // Consume a donation message with the same email no matter the name is different which is cc
    $trustDonateMessage = new RecurDonationMessage([
      'first_name' => 'diff-old-firstname',
      'last_name' => 'Mouse',
      'email' => 'aaa@aa.com',
      'gateway' => 'gravy',
      'payment_method' => 'cc',
      'country' => 'US'
    ]);
    WMFContact::save(FALSE)->setMessage($trustDonateMessage->normalize())->execute();
    $afterContacts = Contact::get(FALSE)
      ->addSelect('id', 'External_Identifiers.venmo_user_name', 'email_primary.email', 'email_primary.location_type_id:name', 'first_name')
      ->addWhere('email_primary.email', '=', 'aaa@aa.com')
      ->execute();
    // Verify that no new contact was created since the email matches old one not trusted, and name updated to the trust one
    $this->assertCount(1, $afterContacts);
    $this->assertEquals('@venmojoe123', $afterContacts[0]['External_Identifiers.venmo_user_name']);
    $this->assertEquals('Home', $afterContacts[0]['email_primary.location_type_id:name']);
    $this->assertEquals($contacts[0]['id'], $afterContacts[0]['id']);
    $this->assertEquals('diff-old-firstname', $afterContacts[0]['first_name']);
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

  public function testTrustedSecondaryPromotesAndDemotesUntrustedPrimary(): void {
    // Step 1: Create contact with untrusted primary (venmo)
    $init = new RecurDonationMessage([
      'first_name' => 'Init',
      'last_name' => 'Mouse',
      'email' => 'venmo_primary@test.org',
      'gateway' => 'braintree',
      'external_identifier' => 'venmo-init',
      'payment_method' => 'venmo',
      'country' => 'US',
    ]);
    WMFContact::save(FALSE)->setMessage($init->normalize())->execute();

    $contact = Contact::get(FALSE)
      ->addWhere('email_primary.email', '=', 'venmo_primary@test.org')
      ->addSelect('id')
      ->execute()->first();

    $this->ids['Contact'][] = $contact['id'];

    // Step 2: Add trusted email (cc)
    $trusted = new DonationMessage([
      'first_name' => 'Trusted',
      'last_name' => 'Mouse',
      'email' => 'trusted_primary@test.org',
      'gateway' => 'adyen',
      'payment_method' => 'cc',
      'contact_id' => $contact['id'],
      'country' => 'US',
    ]);
    WMFContact::save(FALSE)->setMessage($trusted->normalize())->execute();

    $emails = Email::get(FALSE)
      ->addWhere('contact_id', '=', $contact['id'])
      ->addSelect('email', 'is_primary', 'on_hold', 'location_type_id:name')
      ->execute()
      ->indexBy('email');

    // Trusted becomes primary
    $this->assertEquals(1, $emails['trusted_primary@test.org']['is_primary']);
    $this->assertEquals(0, $emails['trusted_primary@test.org']['on_hold']);
    $this->assertEquals('Home', $emails['trusted_primary@test.org']['location_type_id:name']);

    // Old venmo demoted
    $this->assertEquals(0, $emails['venmo_primary@test.org']['is_primary']);
    $this->assertEquals('venmo', $emails['venmo_primary@test.org']['location_type_id:name']);
  }

  public function testDoNotDemoteTrustedPrimary(): void {
    // Step 1: Create contact with trusted primary (cc)
    $init = new DonationMessage([
      'first_name' => 'Init',
      'last_name' => 'Mouse',
      'email' => 'trusted_primary@test.org',
      'gateway' => 'adyen',
      'payment_method' => 'cc',
      'country' => 'US',
    ]);
    WMFContact::save(FALSE)->setMessage($init->normalize())->execute();

    $contact = Contact::get(FALSE)
      ->addWhere('email_primary.email', '=', 'trusted_primary@test.org')
      ->addSelect('id')
      ->execute()->first();

    $this->ids['Contact'][] = $contact['id'];

    // Step 2: Add another trusted email (cc)
    $second = new DonationMessage([
      'first_name' => 'Second',
      'last_name' => 'Mouse',
      'email' => 'another_trusted@test.org',
      'gateway' => 'adyen',
      'payment_method' => 'cc',
      'contact_id' => $contact['id'],
      'country' => 'US',
    ]);
    WMFContact::save(FALSE)->setMessage($second->normalize())->execute();

    $emails = Email::get(FALSE)
      ->addWhere('contact_id', '=', $contact['id'])
      ->addSelect('email', 'is_primary', 'location_type_id:name')
      ->execute();

    // Summary: New trusted as primary should replace old one instead of demote old one since same location type - Home
    $this->assertCount(1, $emails);
    $this->assertEquals(1, $emails[0]['is_primary']);
    $this->assertEquals('another_trusted@test.org', $emails[0]['email']);
    $this->assertEquals('Home', $emails[0]['location_type_id:name']);
  }

  public function testPromotionResetsOnHold(): void {
    // Step 1: Create contact with untrusted primary (venmo)
    $init = new RecurDonationMessage([
      'first_name' => 'Init',
      'last_name' => 'Mouse',
      'email' => 'venmo_on_hold@test.org',
      'gateway' => 'braintree',
      'external_identifier' => 'venmo-hold',
      'payment_method' => 'venmo',
      'country' => 'US',
      'on_hold' => 1,
    ]);
    WMFContact::save(FALSE)->setMessage($init->normalize())->execute();

    $contact = Contact::get(FALSE)
      ->addWhere('email_primary.email', '=', 'venmo_on_hold@test.org')
      ->addSelect('id')
      ->execute()->first();
    // set init email on hold to simulate untrusted email, then when promote to trusted, the on hold in primary should be reset to 0
    Contact::update(FALSE)
      ->addWhere('id', '=', $contact['id'])
      ->setValues(['email_primary.on_hold' => 1])
      ->execute();
    $this->ids['Contact'][] = $contact['id'];

    // Step 2: New trusted donation -> Promote
    $trusted = new DonationMessage([
      'first_name' => 'Promote',
      'last_name' => 'Mouse',
      'email' => 'promoted@test.org',
      'gateway' => 'adyen',
      'payment_method' => 'cc',
      'contact_id' => $contact['id'],
      'country' => 'US',
    ]);
    WMFContact::save(FALSE)->setMessage($trusted->normalize())->execute();

    $emails = Email::get(FALSE)
      ->addWhere('contact_id', '=', $contact['id'])
      ->addSelect('email', 'is_primary', 'location_type_id:name', 'on_hold')
      ->execute()
      ->indexBy('location_type_id:name');

    // Trusted becomes primary
    $this->assertEquals(1, $emails['Home']['is_primary']);
    $this->assertEquals(0, $emails['Home']['on_hold']);
    $this->assertEquals('promoted@test.org', $emails['Home']['email']);

    // Old venmo demoted
    $this->assertEquals(0, $emails['venmo']['is_primary']);
    $this->assertEquals('venmo_on_hold@test.org', $emails['venmo']['email']);
    $this->assertEquals(1, $emails['venmo']['on_hold']);
  }

  public function testSecondaryEmailAddedWithNewLocationType(): void {
    // Step 1: init cc donation
    $cc = new DonationMessage([
      'first_name' => 'init',
      'last_name' => 'Mouse',
      'email' => 'match_primary@test.org',
      'gateway' => 'adyen',
      'payment_method' => 'cc',
      'country' => 'US',
    ]);
    WMFContact::save(FALSE)->setMessage($cc->normalize())->execute();
    $contact = Contact::get(FALSE)
      ->addWhere('email_primary.email', '=', 'match_primary@test.org')
      ->addSelect('id')
      ->execute()->first();
    $this->ids['Contact'][] = $contact['id'];
    // new secondary email should create since match primary but diff type
    $venmo = new RecurDonationMessage([
      'first_name' => 'Venmo',
      'last_name' => 'Mouse',
      'email' => 'match_primary@test.org',
      'phone' => '1237777777',
      'gateway' => 'gravy',
      'external_identifier' => '@venmojoe',
      'payment_method' => 'venmo',
      'country' => 'US',
    ]);
    WMFContact::save(FALSE)->setMessage($venmo->normalize())->execute();

    // new secondary email should create since match primary but diff type
    $paypal = new RecurDonationMessage([
      'first_name' => 'Paypal',
      'last_name' => 'Mouse',
      'email' => 'match_primary@test.org',
      'gateway' => 'gravy',
      'external_identifier' => 'paypal-payer-id',
      'payment_method' => 'paypal',
      'country' => 'US',
    ]);
    WMFContact::save(FALSE)->setMessage($paypal->normalize())->execute();

    // new secondary email should create since match primary but diff type
    $google = new RecurDonationMessage([
      'first_name' => 'Google',
      'last_name' => 'Mouse',
      'email' => 'match_primary@test.org',
      'gateway' => 'adyen',
      'payment_method' => 'google',
      'country' => 'US',
    ]);
    WMFContact::save(FALSE)->setMessage($google->normalize())->execute();

    // new secondary email should create since match primary but diff type
    $apple = new RecurDonationMessage([
      'first_name' => 'Apple',
      'last_name' => 'Mouse',
      'email' => 'match_primary@test.org',
      'gateway' => 'adyen',
      'payment_method' => 'apple',
      'country' => 'US',
    ]);
    WMFContact::save(FALSE)->setMessage($apple->normalize())->execute();

    $emails = Email::get(FALSE)
      ->addWhere('contact_id', '=', $contact['id'])
      ->addSelect('email', 'is_primary', 'location_type_id:name')
      ->execute();

    foreach ($emails as $email) {
      $this->assertEquals('match_primary@test.org', $email['email']);
    }
    // Trusted becomes primary
    $this->assertEquals(1, $emails[0]['is_primary']);
    $this->assertEquals('Home', $emails[0]['location_type_id:name']);

    // Mew venmo added as secondary
    $this->assertEquals(0, $emails[1]['is_primary']);
    $this->assertEquals('venmo', $emails[1]['location_type_id:name']);

    // New paypal added as secondary
    $this->assertEquals(0, $emails[2]['is_primary']);
    $this->assertEquals('paypal', $emails[2]['location_type_id:name']);

    // New google added as secondary
    $this->assertEquals(0, $emails[3]['is_primary']);
    $this->assertEquals('google', $emails[3]['location_type_id:name']);

    // New apple added as secondary
    $this->assertEquals(0, $emails[4]['is_primary']);
    $this->assertEquals('apple', $emails[4]['location_type_id:name']);
  }

  public function testAchMatchContactThenUpdateEmail(): void
  {
    $achMessage = new RecurDonationMessage([
      'first_name' => 'ACH',
      'last_name' => 'Mouse',
      'email' => 'default@test.org',
      'gateway' => 'gravy',
      'billing_email' => 'ach@test.org',
      'payment_method' => 'ach',
      'country' => 'US'
    ]);
    WMFContact::save(FALSE)->setMessage($achMessage->normalize())->execute();
    $contact = Contact::get(FALSE)
      ->addWhere('email_primary.email', '=', 'default@test.org')
      ->addSelect('id')
      ->execute()->first();
    // latest trust should replace the old trust, replace the old trust as this is newer
    $trusted = new DonationMessage([
      'first_name' => 'cc',
      'last_name' => 'Mouse',
      'email' => 'cc@test.org',
      'gateway' => 'adyen',
      'payment_method' => 'cc',
      'contact_id' => $contact['id'],
      'country' => 'US',
    ]);
    WMFContact::save(FALSE)->setMessage($trusted->normalize())->execute();
    $emails = Email::get(FALSE)
      ->addWhere('contact_id', '=', $contact['id'])
      ->addSelect('email', 'is_primary', 'location_type_id:name', 'contact_id.first_name')
      ->execute()
      ->indexBy('location_type_id:name');

    // new trusted becomes primary but still use Home to indicate name trust
    $this->assertEquals(1, $emails['Home']['is_primary']);
    $this->assertEquals('cc@test.org', $emails['Home']['email']);
    // ach name is not from donation form, so should get update when trust source name come
    $this->assertEquals('cc', $emails['Home']['contact_id.first_name']);

    // ach as secondary
    $this->assertEquals(0, $emails['ach']['is_primary']);
    $this->assertEquals('ach@test.org', $emails['ach']['email']);
    // new ach should be able to match the same cid by compare billing_email
    $achMessage = new RecurDonationMessage([
      'first_name' => 'ACH second',
      'last_name' => 'Mouse',
      'email' => 'default@test.org',
      'gateway' => 'gravy',
      'billing_email' => 'ach@test.org',
      'payment_method' => 'ach',
      'country' => 'US'
    ]);
    WMFContact::save(FALSE)->setMessage($achMessage->normalize())->execute();
    // should change it back since use billing email to match existing contact, then update trust primary
    $emails2 = Email::get(FALSE)
      ->addWhere('contact_id', '=', $contact['id'])
      ->addSelect('email', 'is_primary', 'location_type_id:name')
      ->execute()
      ->indexBy('location_type_id:name');
    // newest ach email becomes primary
    $this->assertEquals(1, $emails2['Home']['is_primary']);
    $this->assertEquals('default@test.org', $emails2['Home']['email']);

    // ach still as secondary
    $this->assertEquals(0, $emails2['ach']['is_primary']);
    $this->assertEquals('ach@test.org', $emails2['ach']['email']);
    // new ach email should get updated with same primary email and name match
    $ach2Message = new RecurDonationMessage([
      'first_name' => 'cc',
      'last_name' => 'Mouse',
      'email' => 'default@test.org',
      'gateway' => 'gravy',
      'billing_email' => 'ach+updated@test.org',
      'payment_method' => 'ach',
      'country' => 'US'
    ]);
    WMFContact::save(FALSE)->setMessage($ach2Message->normalize())->execute();
    $emails3 = Email::get(FALSE)
      ->addWhere('contact_id', '=', $contact['id'])
      ->addSelect('email', 'is_primary', 'location_type_id:name', 'contact_id.first_name')
      ->execute()
      ->indexBy('location_type_id:name');
    $this->assertEquals(1, $emails3['Home']['is_primary']);
    $this->assertEquals('default@test.org', $emails3['Home']['email']);
    $this->assertEquals('cc', $emails3['Home']['contact_id.first_name']);

    // ach new updated still as secondary
    $this->assertEquals(0, $emails3['ach']['is_primary']);
    $this->assertEquals('ach+updated@test.org', $emails3['ach']['email']);
  }

  public function testContactHasOnlyOnePerLocationType(): void {
    $message = new RecurDonationMessage([
      'first_name' => 'UniqueLocationType',
      'last_name' => 'Mouse',
      'email' => 'unique@test.com',
      'gateway' => 'gravy',
      'payment_method' => 'apple',
      'country' => 'US',
    ]);
    WMFContact::save(FALSE)->setMessage($message->normalize())->execute();
    $contact = Contact::get(FALSE)
      ->addWhere('email_primary.email', '=', 'unique@test.com')
      ->addSelect('id')
      ->execute()->first();
    $this->ids['Contact'][] = $contact['id'];
    // add another email with same location type venmo, should update the existing one not create new one
    $message2 = new DonationMessage([
      'first_name' => 'UniqueUpdateLocationType',
      'last_name' => 'Mouse',
      'email' => 'uniqueupdate@test.com',
      'gateway' => 'gravy',
      'payment_method' => 'apple',
      'country' => 'US',
      'contact_id' => $contact['id'],
    ]);
    WMFContact::save(FALSE)->setMessage($message2->normalize())->execute();
    $emails = Email::get(FALSE)
      ->addWhere('contact_id', '=', $contact['id'])
      ->addSelect('email', 'is_primary', 'location_type_id:name')
      ->execute();
    $this->assertCount(1, $emails);
    $this->assertEquals('uniqueupdate@test.com', $emails[0]['email']);
    $this->assertEquals(1, $emails[0]['is_primary']);
    $this->assertEquals('apple', $emails[0]['location_type_id:name']);
    $message3 = new RecurDonationMessage([
      'first_name' => 'TrustACH',
      'last_name' => 'Mouse',
      'email' => 'uniqueupdate@test.com',
      'billing_email' => 'uniqueupdate@test.com',
      'gateway' => 'gravy',
      'payment_method' => 'ach',
      'country' => 'US',
    ]);
    WMFContact::save(FALSE)->setMessage($message3->normalize())->execute();
    $message4 = new RecurDonationMessage([
      'first_name' => 'TrustACH',
      'last_name' => 'Mouse',
      'email' => 'uniqueupdate@test.com',
      'billing_email' => 'uniqueupdate2@test.com',
      'gateway' => 'gravy',
      'payment_method' => 'ach',
      'country' => 'US',
    ]);
    WMFContact::save(FALSE)->setMessage($message4->normalize())->execute();
    $emails2 = Email::get(FALSE)
      ->addWhere('contact_id', '=', $contact['id'])
      ->addSelect('email', 'is_primary', 'location_type_id:name')
      ->setOrderBy(['id'=> 'ASC'])
      ->execute();
    // this two ach will map the existing primary and take over the apple one with newly added primary email
    $this->assertCount(3, $emails2);
    $this->assertEquals('uniqueupdate@test.com', $emails2[0]['email']);
    $this->assertEquals('uniqueupdate2@test.com', $emails2[1]['email']);
    $this->assertEquals('uniqueupdate@test.com', $emails2[2]['email']);
    $this->assertEquals(0, $emails2[0]['is_primary']);
    $this->assertEquals(0, $emails2[1]['is_primary']);
    $this->assertEquals(1, $emails2[2]['is_primary']);
    $this->assertEquals('apple', $emails2[0]['location_type_id:name']);
    $this->assertEquals('ach', $emails2[1]['location_type_id:name']);
    $this->assertEquals('Home', $emails2[2]['location_type_id:name']);
    $this->assertEquals(1, $emails2[2]['is_primary']);
    // also the name should use the trusted source which is TrustACH
    $contact = Contact::get(FALSE)
      ->addWhere('id', '=', $contact['id'])
      ->addSelect('email_primary.email', 'email_primary.location_type_id:name', 'first_name')
      ->execute()->first();
    $this->assertEquals('UniqueLocationType', $contact['first_name']);
    $this->assertEquals('uniqueupdate@test.com', $contact['email_primary.email']);
    $this->assertEquals('Home', $contact['email_primary.location_type_id:name']);
  }

  // Priority #1 - Primary email + name
  public function testMatchByPrimaryEmailAndName(): void {
    $message = new RecurDonationMessage([
      'first_name' => 'John',
      'last_name' => 'Mouse',
      'email' => 'john@test.org',
      'gateway' => 'gravy',
      'payment_method' => 'cc',
      'country' => 'US',
    ]);
    WMFContact::save(FALSE)->setMessage($message->normalize())->execute();

    // Same email + same name → should match immediately
    $message2 = new RecurDonationMessage([
      'first_name' => 'John',
      'last_name' => 'Mouse',
      'email' => 'john@test.org',
      'gateway' => 'gravy',
      'payment_method' => 'ach',
      'country' => 'US',
    ]);
    WMFContact::save(FALSE)->setMessage($message2->normalize())->execute();

    $emails = Email::get(FALSE)
      ->addWhere('email', '=', 'john@test.org')
      ->execute();

    $this->assertCount(1, $emails);
  }

  // Priority #2 - Email + name (non-primary)
  public function testMatchByEmailAndName(): void {
    // Create contact
    $msg1 = new RecurDonationMessage([
      'first_name' => 'Jane',
      'last_name' => 'Mouse',
      'email' => 'primary@test.org',
      'gateway' => 'gravy',
      'payment_method' => 'cc',
      'country' => 'US',
    ]);
    WMFContact::save(FALSE)->setMessage($msg1->normalize())->execute();

    $contact = Contact::get(FALSE)
      ->addWhere('email_primary.email', '=', 'primary@test.org')
      ->addSelect('id')
      ->execute()->first();
    $this->ids['Contact'][] = $contact['id'];

    // Add secondary email
    Email::create(FALSE)
      ->addValue('contact_id', $contact['id'])
      ->addValue('email', 'secondary@test.org')
      ->addValue('location_type_id:name', 'apple')
      ->execute();

    // Match attempt: Same Name + Secondary Email
    $msg3 = new RecurDonationMessage([
      'first_name' => 'Jane',
      'last_name' => 'Mouse',
      'email' => 'secondary@test.org',
      'gateway' => 'gravy',
      'payment_method' => 'google',
      'country' => 'US',
    ]);
    WMFContact::save(FALSE)->setMessage($msg3->normalize())->execute();

    $emails = Email::get(FALSE)
      ->addWhere('email', '=', 'secondary@test.org')
      ->addSelect('contact_id')
      ->execute();

    // msg3 should matched msg2 then only one contact
    $this->assertCount(2, $emails);
    $this->assertEquals($emails[0]['contact_id'], $emails[1]['contact_id']);
  }

  // Priority #3 — Primary + low-confidence
  public function testMatchByPrimaryLowConfidence(): void {
    $msg1 = new RecurDonationMessage([
      'first_name' => 'Confidence',
      'last_name' => 'Mouse',
      'email' => 'low@test.org',
      'gateway' => 'gravy',
      'payment_method' => 'cc',
      'country' => 'US',
    ]);
    WMFContact::save(FALSE)->setMessage($msg1->normalize())->execute();

    // Name mismatch but primary + low-confidence location
    $msg2 = new RecurDonationMessage([
      'first_name' => 'LowConfidence',
      'last_name' => 'Mouse',
      'email' => 'low@test.org',
      'gateway' => 'gravy',
      'payment_method' => 'paypal',
      'country' => 'US',
    ]);
    WMFContact::save(FALSE)->setMessage($msg2->normalize())->execute();

    $emails = Email::get(FALSE)
      ->addWhere('email', '=', 'low@test.org')
      ->addSelect('location_type_id:name', 'contact_id')
      ->execute();

    $this->assertCount(2, $emails);
    $this->assertEquals('Home', $emails[0]['location_type_id:name']);
    $this->assertEquals('paypal', $emails[1]['location_type_id:name']);
    $this->assertEquals($emails[0]['contact_id'], $emails[1]['contact_id']);
  }

  // Priority #4 — Email + location type
  public function testMatchByEmailAndLocationType(): void {
    // Attach apple email
    $msg1 = new RecurDonationMessage([
      'first_name' => 'Apple',
      'last_name' => 'Mouse',
      'email' => 'apple@test.org',
      'gateway' => 'gravy',
      'payment_method' => 'apple',
      'country' => 'US'
    ]);
    WMFContact::save(FALSE)->setMessage($msg1->normalize())->execute();

    // Should match via location type
    $msg2 = new RecurDonationMessage([
      'first_name' => 'Different',
      'last_name' => 'Mouse',
      'email' => 'apple@test.org',
      'gateway' => 'gravy',
      'payment_method' => 'apple',
      'country' => 'US',
    ]);
    WMFContact::save(FALSE)->setMessage($msg2->normalize())->execute();

    $emails = Email::get(FALSE)
      ->addWhere('email', '=', 'apple@test.org')
      ->execute();

    $this->assertCount(1, $emails);
  }

  // Priority #5 — Low-confidence email match fallback
  public function testMatchByLowConfidenceFallback(): void {
    $msg1 = new RecurDonationMessage([
      'first_name' => 'Fallback',
      'last_name' => 'Mouse',
      'email' => 'fallback@test.org',
      'gateway' => 'gravy',
      'payment_method' => 'paypal',
      'country' => 'US',
    ]);
    WMFContact::save(FALSE)->setMessage($msg1->normalize())->execute();

    // No name match, no location match → fallback
    $msg2 = new RecurDonationMessage([
      'first_name' => 'Another',
      'last_name' => 'Mouse',
      'email' => 'fallback@test.org',
      'gateway' => 'gravy',
      'payment_method' => 'google',
      'country' => 'US',
    ]);
    WMFContact::save(FALSE)->setMessage($msg2->normalize())->execute();

    $emails = Email::get(FALSE)
      ->addWhere('email', '=', 'fallback@test.org')
      ->addSelect('contact_id')
      ->execute();

    $this->assertCount(2, $emails);
    $this->assertEquals($emails[0]['contact_id'], $emails[1]['contact_id']);
  }

  public function testMapContactWithAchFormEmail(): void
  {
    // make donation with ach email as primary
    $achInitMessage = new RecurDonationMessage([
      'first_name' => 'ACH',
      'last_name' => 'Mouse',
      'email' => 'init-ach@test.org',
      'gateway' => 'gravy',
      'payment_method' => 'ach',
      'billing_email' => 'ach-from-bank@mouse.com',
      'country' => 'US',
    ]);
    WMFContact::save(FALSE)->setMessage($achInitMessage->normalize())->execute();
    $contact = Contact::get(FALSE)
      ->addWhere('email_primary.email', '=', 'init-ach@test.org')
      ->addSelect('id')
      ->execute()->first();
    $this->ids['Contact'][] = $contact['id'];
    $emails = Email::get(FALSE)
      ->addWhere('contact_id', '=', $contact['id'])
      ->addSelect('is_primary', 'location_type_id:name', 'email', 'contact_id.first_name')
      ->setOrderBy(['id'=> 'ASC'])
      ->execute();
    $this->assertCount(2, $emails);
    $this->assertEquals('init-ach@test.org', $emails[0]['email']);
    $this->assertEquals('achForm', $emails[0]['location_type_id:name']); // update to Home, since trusted name source get replaced
    $this->assertEquals('ach-from-bank@mouse.com', $emails[1]['email']);
    $this->assertEquals('ach', $emails[1]['location_type_id:name']);
    $this->assertEquals('ACH', $emails[0]['contact_id.first_name']);
    // map cc with primary email but not match name since achForm is also untrusted name source as primary name
    // make donation with ach email as primary, and since both achForm and Home is trusted email source, keep one achForm replaced to home
    $ccMessage = new RecurDonationMessage([
      'first_name' => 'CC',
      'last_name' => 'Mouse',
      'email' => 'init-ach@test.org',
      'gateway' => 'gravy',
      'payment_method' => 'cc',
      'country' => 'US'
    ]);
    WMFContact::save(FALSE)->setMessage($ccMessage->normalize())->execute();
    $emailUpdates = Email::get(FALSE)
      ->addWhere('contact_id', '=', $contact['id'])
      ->addSelect('is_primary', 'location_type_id:name', 'email', 'contact_id.first_name')
      ->setOrderBy(['id'=> 'ASC'])
      ->execute();
    $this->assertCount(2, $emailUpdates);
    // since both cc and achForm is trusted email, the newer one replace the old one as primary, but name remain the old cc one
    $this->assertEquals('init-ach@test.org', $emailUpdates[0]['email']);
    $this->assertEquals('Home', $emailUpdates[0]['location_type_id:name']); // update to Home, since trusted name source get replaced
    $this->assertEquals('ach-from-bank@mouse.com', $emailUpdates[1]['email']);
    $this->assertEquals('ach', $emailUpdates[1]['location_type_id:name']);
    $this->assertEquals('CC', $emailUpdates[0]['contact_id.first_name']);
  }
}

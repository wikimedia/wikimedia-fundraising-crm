<?php

namespace Civi\Api4\WMFContact;

use Civi\API\Exception\UnauthorizedException;
use Civi\Api4\Activity;
use Civi\Api4\QueueItem;
use Civi\Api4\WMFContact;
use Civi\Api4\Email;
use PHPUnit\Framework\TestCase;
use Civi\Api4\Address;
use Civi\Api4\Contact;
use Civi\Test\EntityTrait;
use Civi\WMFEnvironmentTrait;

/**
 * This is a generic test class for the extension (implemented with PHPUnit).
 * @group epcV4
 **/
class UpdateCommunicationsPreferencesTest extends TestCase {
  use WMFEnvironmentTrait;
  use EntityTrait;

  protected $contactID;

  public function tearDown(): void {
    QueueItem::delete(FALSE)
      ->addWhere('queue_name', '=', 'omni-snooze')
      ->execute();
    parent::tearDown();
  }

  /**
   * Test use of API4 in EmailPreferenceCenterQueueConsumer
   *
   * @throws \CRM_Core_Exception
   */
  public function testUpdateEmailPreferenceCenter(): void {
    $this->contactID = Contact::create(FALSE)->setValues([
      'first_name' => 'Bob',
      'last_name' => 'McTest',
      'Communication.opt_in' => 0,
      'contact_type' => 'Individual',
      'preferred_language' => 'fr_CA',
    ])->addChain('address', Address::create(FALSE)
      ->addValue('contact_id', '$id')
      ->addValue('country_id:name', 'CA')
      ->addValue('location_type_id:name', 'Home')
      ->addValue('is_primary', 1)
    ) ->addChain('email', Email::create(FALSE)
      ->addValue('contact_id', '$id')
      ->addValue('email', 'bob.roberto@test.com')
      ->addValue('location_type_id:name', 'Home')
    )
      ->execute()->first()['id'];

    $checksum = \CRM_Contact_BAO_Contact_Utils::generateChecksum($this->contactID);
    $emailChecksum = hash('sha256', $this->contactID);
    $this->setSetting('wmf_set_primary_email_from_name', 'Set Primary Email Sender');
    $this->setSetting('wmf_set_primary_email_from_address', 'verify@example.org');
    WMFContact::updateCommunicationsPreferences()
      ->setEmail('test1@example.org')
      ->setContactID($this->contactID)
      ->setChecksum($checksum)
      ->setEmailChecksum($emailChecksum)
      ->setCountry('US')
      ->setLanguage('es_US')
      ->setSnoozeDate(null)
      ->setSendEmail('true')
      ->execute();

    $contact = Contact::get(FALSE)->addWhere('id', '=', (int) $this->contactID)
      ->setSelect(['preferred_language', 'Communication.opt_in'])
      ->execute()->first();

    $address = Address::get(FALSE)
      ->addWhere('contact_id', '=', (int) $this->contactID)
      ->addWhere('is_primary', '=', 1)
      ->addWhere('location_type_id:name', '=', 'EmailPreference')
      ->addSelect('country_id.iso_code')
      ->execute()
      ->first();

    $email = Email::get(FALSE)
      ->addWhere('contact_id', '=', (int) $this->contactID)
      ->addWhere('is_primary', '=', 1)
      ->execute()
      ->first();

    $this->assertEquals(1, $contact['Communication.opt_in']);
    $this->assertEquals('es_US', $contact['preferred_language']);
    $this->assertEquals('US', $address['country_id.iso_code']);

    $activityDetail = Activity::get(FALSE)
      ->addWhere('source_contact_id', '=', (int) $this->contactID)
      ->addWhere('source_record_id', '=', (int) $this->contactID)
      ->addWhere('activity_type_id:name', '=', 'Send Verification Email')
      ->setSelect(['details'])
      ->execute()
      ->last()['details'];
    $this->assertEquals('bob.roberto@test.com', $email['email']);
    $this->assertStringContainsString("Try to update EmailPreference email from bob.roberto@test.com to test1@example.org and send verification email.", $activityDetail);

    $sentEmail = $this->getMostRecentEmail();
    $this->assertStringContainsString('From: Set Primary Email Sender <verify@example.org>', $sentEmail['headers']);

    WMFContact::updateCommunicationsPreferences()
      ->setEmail('test2@example.org')
      ->setContactID($this->contactID)
      ->setChecksum($checksum)
      ->setCountry('AF')
      ->setLanguage('pt_BR')
      ->setSnoozeDate(null)
      ->setSendEmail(null)
      ->setEmailChecksum($emailChecksum)
      ->execute();

    $contact2 = Contact::get(FALSE)->addWhere('id', '=', (int) $this->contactID)
      ->setSelect(['preferred_language', 'Communication.opt_in'])
      ->execute()->first();

    $address2 = Address::get(FALSE)
      ->addWhere('contact_id', '=', (int) $this->contactID)
      ->addWhere('is_primary', '=', 1)
      ->addWhere('location_type_id:name', '=', 'EmailPreference')
      ->addSelect('country_id.iso_code')
      ->execute()
      ->first();

    $email2 = Email::get(FALSE)
      ->addWhere('contact_id', '=', (int) $this->contactID)
      ->addWhere('is_primary', '=', 1)
      ->execute()
      ->first();

    $this->assertEquals(1, $contact2['Communication.opt_in']);
    $this->assertEquals('pt_BR', $contact2['preferred_language']);
    $this->assertEquals('AF', $address2['country_id.iso_code']);
    $this->assertEquals('bob.roberto@test.com', $email2['email']);
    $activityDetail2 = Activity::get(FALSE)
      ->addWhere('source_contact_id', '=', (int) $this->contactID)
      ->addWhere('source_record_id', '=', (int) $this->contactID)
      ->addWhere('activity_type_id:name', '=', 'Send Verification Email')
      ->setSelect(['details'])
      ->execute()
      ->last()['details'];
    $this->assertStringContainsString("Try to update EmailPreference email from bob.roberto@test.com to test2@example.org and send verification email.", $activityDetail2);

    Contact::update(FALSE)
      ->addWhere('id', '=', (int) $this->contactID)
      ->addValue('is_opt_out', TRUE)
      ->execute();

    // only update send_email
    WMFContact::updateCommunicationsPreferences()
      ->setEmail('test3@example.org')
      ->setContactID($this->contactID)
      ->setChecksum($checksum)
      ->setCountry(null)
      ->setLanguage(null)
      ->setSnoozeDate(null)
      ->setSendEmail('false')
      ->setEmailChecksum($emailChecksum)
      ->execute();
    $contact3 = Contact::get(FALSE)->addWhere('id', '=', (int) $this->contactID)
      ->setSelect(['preferred_language', 'Communication.opt_in', 'is_opt_out'])
      ->execute()->first();

    $address3 = Address::get(FALSE)
      ->addWhere('contact_id', '=', (int) $this->contactID)
      ->addWhere('is_primary', '=', 1)
      ->addWhere('location_type_id:name', '=', 'EmailPreference')
      ->addSelect('country_id.iso_code')
      ->execute()
      ->first();

    $email3 = Email::get(FALSE)
      ->addWhere('contact_id', '=', (int) $this->contactID)
      ->addWhere('is_primary', '=', 1)
      ->execute()
      ->first();

    $this->assertEquals(0, $contact3['Communication.opt_in']);
    // Opting out must not clear No Bulk Emails.
    $this->assertTrue($contact3['is_opt_out']);
    // others remain the same
    $this->assertEquals('pt_BR', $contact3['preferred_language']);
    $this->assertEquals('AF', $address3['country_id.iso_code']);
    $this->assertEquals('bob.roberto@test.com', $email3['email']);
    $activityDetail3 = Activity::get(FALSE)
      ->addWhere('source_contact_id', '=', (int) $this->contactID)
      ->addWhere('source_record_id', '=', (int) $this->contactID)
      ->addWhere('activity_type_id:name', '=', 'Send Verification Email')
      ->setSelect(['details'])
      ->execute()
      ->last()['details'];
    $this->assertStringContainsString("Try to update EmailPreference email from bob.roberto@test.com to test3@example.org and send verification email.", $activityDetail3);

  }

  public function testMissingRequiredParams() {
    $this->contactID = Contact::create(FALSE)->setValues([
      'first_name' => 'Bob',
      'last_name' => 'McTest',
      'contact_type' => 'Individual',
    ])->execute()->first()['id'];

    $this->expectException( \CRM_Core_Exception::class );
    $this->expectExceptionMessage( 'Parameter "email" is required.' );
    $checksum = \CRM_Contact_BAO_Contact_Utils::generateChecksum($this->contactID);
    // no email which is required
    WMFContact::updateCommunicationsPreferences()
      ->setEmail(null)
      ->setContactID($this->contactID)
      ->setChecksum($checksum)
      ->setCountry(null)
      ->setLanguage(null)
      ->setSnoozeDate(null)
      ->setSendEmail(null)
      ->setEmailChecksum(hash('sha256', $this->contactID))
      ->execute();
  }

  public function testMissingEmailChecksumWhenEmailUpdate(): void {
    $this->contactID = Contact::create(FALSE)->setValues([
      'first_name' => 'Bob',
      'last_name' => 'McTest',
      'contact_type' => 'Individual',
    ])->addChain('email', Email::create(FALSE)
      ->addValue('contact_id', '$id')
      ->addValue('email', 'bob.roberto@test.com')
      ->addValue('location_type_id:name', 'Home')
    )->execute()->first()['id'];

    $checksum = \CRM_Contact_BAO_Contact_Utils::generateChecksum($this->contactID);
    // no email checksum while email address different
    try {
      $result = WMFContact::updateCommunicationsPreferences()
        ->setEmail('bob.roberto+update@test.com')
        ->setContactID($this->contactID)
        ->setChecksum($checksum)
        ->setCountry(null)
        ->setLanguage(null)
        ->setSnoozeDate(null)
        ->setSendEmail(null)
        ->setEmailChecksum(null)
        ->execute()->first();
      // This is a bit of a dummy check to avoid it complaining "This test did not perform any assertions"
      // It would be better to check the outcome, but we don't return something in this scenario and
      // perhaps that's right not to.
      $this->assertIsArray($result);
    }
    catch (\Exception $e) {
      $this->fail('An unexpected exception was thrown: ' . $e->getMessage());
    }
  }

  public function testChecksumMismatch() {
    $this->contactID = Contact::create(FALSE)->setValues([
      'first_name' => 'Bob',
      'last_name' => 'McTest',
      'contact_type' => 'Individual',
    ])->execute()->first()['id'];

    $this->expectException( \CRM_Core_Exception::class );
    $this->expectExceptionMessage( 'Checksum mismatch.' );
    $checksum = \CRM_Contact_BAO_Contact_Utils::generateChecksum($this->contactID);
    WMFContact::updateCommunicationsPreferences()
      ->setEmail('test@example.org')
      ->setContactID($this->contactID)
      ->setChecksum($checksum . '01')
      ->setCountry(null)
      ->setLanguage(null)
      ->setSnoozeDate(null)
      ->setSendEmail(null)
      ->setEmailChecksum(hash('sha256', $this->contactID))
      ->execute();
  }

  public function testNoEmailUpdateSoNoEmailChecksumNeeded() {
    $this->contactID = Contact::create(FALSE)->setValues([
      'first_name' => 'Bob',
      'last_name' => 'McTest',
      'contact_type' => 'Individual',
      'preferred_language' => 'fr_CA',
    ])->addChain('email', Email::create(FALSE)
      ->addValue('contact_id', '$id')
      ->addValue('email', 'bob.roberto@test.com')
      ->addValue('location_type_id:name', 'Home')
    )->execute()->first()['id'];

    $prefLang = Contact::get(FALSE)->addWhere('id', '=', (int) $this->contactID)
      ->setSelect(['preferred_language'])
      ->execute()
      ->first()['preferred_language'];
    $this->assertEquals('fr_CA', $prefLang);

    $checksum = \CRM_Contact_BAO_Contact_Utils::generateChecksum($this->contactID);
    WMFContact::updateCommunicationsPreferences()
      ->setEmail('bob.roberto@test.com')
      ->setContactID($this->contactID)
      ->setChecksum($checksum)
      ->setCountry(null)
      ->setLanguage('es_US')
      ->setSnoozeDate(null)
      ->setSendEmail(null)
      ->setEmailChecksum(null)
      ->execute();

    $prefLangUpdated = Contact::get(FALSE)->addWhere('id', '=', (int) $this->contactID)
      ->setSelect(['preferred_language'])
      ->execute()
      ->first()['preferred_language'];
    $this->assertEquals('es_US', $prefLangUpdated);
  }

  /**
   * @throws \CRM_Core_Exception
   * @throws UnauthorizedException
   */
  public function testUpdateMergedContact(){
    $contactID = Contact::create(FALSE)->setValues([
      'first_name' => 'Bob',
      'last_name' => 'McTest',
      'contact_type' => 'Individual',
    ])->execute()->first()['id'];

    $contactID2 = Contact::create(FALSE)->setValues([
      'first_name' => 'Bob',
      'last_name' => 'McTest',
      'contact_type' => 'Individual',
    ])->execute()->first()['id'];

    $checksum = \CRM_Contact_BAO_Contact_Utils::generateChecksum($contactID);

    Contact::mergeDuplicates(FALSE)
      ->setContactId($contactID2)
      ->setDuplicateId($contactID)
      ->execute();

    // update the merged contact pref lang
    WMFContact::updateCommunicationsPreferences()
      ->setEmail('test@example.org')
      ->setContactID($contactID)
      ->setChecksum($checksum)
      ->setCountry(null)
      ->setLanguage('es_US')
      ->setSnoozeDate(null)
      ->setEmailChecksum(hash('sha256', $contactID))
      ->setSendEmail(null)
      ->execute();
      $prefLang = Contact::get(FALSE)->addWhere('id', '=', (int) $contactID)
        ->setSelect(['preferred_language'])
        ->execute()->first()['preferred_language'];
      $prefLang2 = Contact::get(FALSE)->addWhere('id', '=', (int) $contactID2)
        ->setSelect(['preferred_language'])
        ->execute()
        ->first()['preferred_language'];
    $this->assertNotEquals('es_US', $prefLang);
    $this->assertEquals('es_US', $prefLang2);
  }

  public function testInvalidEmail() {
    $this->contactID = Contact::create(FALSE)->setValues([
      'first_name' => 'Bob',
      'last_name' => 'McTest',
      'contact_type' => 'Individual',
    ])->execute()->first()['id'];

    $this->expectException( \CRM_Core_Exception::class );
    $this->expectExceptionMessage( 'Invalid data in e-mail preferences message.' );
    $checksum = \CRM_Contact_BAO_Contact_Utils::generateChecksum($this->contactID);
    WMFContact::updateCommunicationsPreferences()
      ->setEmail('123')
      ->setContactID($this->contactID)
      ->setChecksum($checksum)
      ->setCountry(null)
      ->setLanguage(null)
      ->setSnoozeDate(null)
      ->setSendEmail(null)
      ->setEmailChecksum(hash('sha256', $this->contactID))
      ->execute();
  }

  public function testSetSnoozePreference() {
    $this->contactID = Contact::create(FALSE)->setValues([
      'first_name' => 'Bob',
      'last_name' => 'McTest',
      'Communication.opt_in' => 1,
      'contact_type' => 'Individual',
      'preferred_language' => 'fr_CA',
    ])->addChain('address', Address::create(FALSE)
      ->addValue('contact_id', '$id')
      ->addValue('country_id:name', 'CA')
      ->addValue('location_type_id:name', 'Home')
      ->addValue('is_primary', 1)
    ) ->addChain('email', Email::create(FALSE)
      ->addValue('contact_id', '$id')
      ->addValue('email', 'bob.roberto@test.com')
      ->addValue('location_type_id:name', 'Home')
    )
      ->execute()->first()['id'];

    $checksum = \CRM_Contact_BAO_Contact_Utils::generateChecksum($this->contactID);
    $emailChecksum = hash('sha256', $this->contactID);
    WMFContact::updateCommunicationsPreferences()
      ->setEmail('bob.roberto@test.com')
      ->setContactID($this->contactID)
      ->setChecksum($checksum)
      ->setEmailChecksum($emailChecksum)
      ->setCountry('US')
      ->setLanguage('es_US')
      ->setSnoozeDate('2035-10-21')
      ->setSendEmail('snooze')
      ->execute();

    $contact = Contact::get(FALSE)->addWhere('id', '=', (int) $this->contactID)
      ->setSelect(['email_primary.email_settings.snooze_date', 'Communication.opt_in'])
      ->execute()->first();

    $this->assertEquals('2035-10-21', $contact['email_primary.email_settings.snooze_date']);
    $this->assertTrue($contact['Communication.opt_in']);
  }

  /**
   * Opting in via the preference center opts in every contact sharing the primary email.
   */
  public function testOptInAppliesToAllContactsWithSamePrimaryEmail(): void {
    $email = 'shared-optin@example.org';
    $snoozeDate = date('Y-m-d', strtotime('+10 days'));
    $contactID = $this->createIndividual([
      'email_primary.email' => $email,
      'email_primary.email_settings.snooze_date' => $snoozeDate,
      'Communication.opt_in' => FALSE,
      'is_opt_out' => TRUE,
    ], 'opted_out');
    $duplicateID = $this->createIndividual([
      'email_primary.email' => $email,
      'email_primary.email_settings.snooze_date' => $snoozeDate,
      'Communication.opt_in' => FALSE,
      'do_not_email' => TRUE,
    ], 'duplicate');

    WMFContact::updateCommunicationsPreferences()
      ->setEmail($email)
      ->setContactID($contactID)
      ->setChecksum(\CRM_Contact_BAO_Contact_Utils::generateChecksum($contactID))
      ->setSendEmail('true')
      ->execute();

    $contacts = Contact::get(FALSE)
      ->addWhere('id', 'IN', [$contactID, $duplicateID])
      ->setSelect(['Communication.opt_in', 'is_opt_out', 'do_not_email', 'email_primary.email_settings.snooze_date'])
      ->execute()->indexBy('id');
    foreach ($contacts as $contact) {
      $this->assertTrue($contact['Communication.opt_in'], "Contact {$contact['id']} opt_in");
      $this->assertFalse($contact['is_opt_out'], "Contact {$contact['id']} is_opt_out");
      $this->assertFalse($contact['do_not_email'], "Contact {$contact['id']} do_not_email");
    }
    // A snooze on any primary email stops the address being emailed, so both are shortened.
    $this->assertEquals(date('Y-m-d', strtotime('+1 day')), $contacts[$contactID]['email_primary.email_settings.snooze_date']);
    $this->assertEquals(date('Y-m-d', strtotime('+1 day')), $contacts[$duplicateID]['email_primary.email_settings.snooze_date']);
  }

  /**
   * When the email is also being changed only the requesting contact is opted in.
   */
  public function testOptInWithEmailChangeDoesNotApplyToOtherContactsWithSameEmail(): void {
    $email = 'shared-optin-change@example.org';
    $contactID = $this->createIndividual([
      'email_primary.email' => $email,
      'Communication.opt_in' => FALSE,
      'is_opt_out' => TRUE,
    ], 'opted_out');
    $duplicateID = $this->createIndividual([
      'email_primary.email' => $email,
      'Communication.opt_in' => FALSE,
      'do_not_email' => TRUE,
    ], 'duplicate');
    WMFContact::updateCommunicationsPreferences()
      ->setEmail('new-address@example.org')
      ->setContactID($contactID)
      ->setChecksum(\CRM_Contact_BAO_Contact_Utils::generateChecksum($contactID))
      ->setEmailChecksum(hash('sha256', $contactID))
      ->setSendEmail('true')
      ->execute();

    $contacts = Contact::get(FALSE)
      ->addWhere('id', 'IN', [$contactID, $duplicateID])
      ->setSelect(['Communication.opt_in', 'is_opt_out', 'do_not_email'])
      ->execute()->indexBy('id');
    $this->assertTrue($contacts[$contactID]['Communication.opt_in']);
    $this->assertFalse($contacts[$contactID]['is_opt_out']);
    $this->assertFalse($contacts[$duplicateID]['Communication.opt_in']);
    $this->assertTrue($contacts[$duplicateID]['do_not_email']);
  }

  /**
   * An already opted in contact opting in again opts in others sharing the primary email and adds an activity.
   */
  public function testOptedInContactOptsInOtherContactsWithSamePrimaryEmail(): void {
    $email = 'shared-optin-note@example.org';
    $contactID = $this->createIndividual([
      'email_primary.email' => $email,
      'Communication.opt_in' => TRUE,
    ], 'opted_in');
    $otherID = $this->createIndividual([
      'email_primary.email' => $email,
      'Communication.opt_in' => FALSE,
      'is_opt_out' => TRUE,
      'email_primary.email_settings.snooze_date' => date('Y-m-d', strtotime('+10 days')),
    ], 'other');

    $this->optInViaPreferenceCenter($contactID, $email);

    $other = Contact::get(FALSE)
      ->addWhere('id', '=', $otherID)
      ->setSelect(['Communication.opt_in', 'is_opt_out', 'email_primary.email_settings.snooze_date'])
      ->execute()->first();
    $this->assertTrue($other['Communication.opt_in']);
    $this->assertFalse($other['is_opt_out']);
    $this->assertEquals(date('Y-m-d', strtotime('+1 day')), $other['email_primary.email_settings.snooze_date']);
    $details = $this->getActivity($contactID, 'OptIn')['details'];
    $this->assertStringContainsString('opted in this primary email', $details);
    $this->assertStringNotContainsString('opt_in from', $details);
  }

  /**
   * An opted in contact whose primary email is not shared with an opted out contact gets no activity.
   *
   * An expired snooze date is not an opt in change, so it does not trigger one.
   */
  public function testOptedInContactWithNoOptedOutSharersGetsNoActivity(): void {
    $email = 'unshared-optin@example.org';
    $contactID = $this->createIndividual([
      'email_primary.email' => $email,
      'Communication.opt_in' => TRUE,
      'email_primary.email_settings.snooze_date' => date('Y-m-d', strtotime('-10 days')),
    ], 'opted_in');

    $this->optInViaPreferenceCenter($contactID, $email);

    $this->assertCount(0, Activity::get(FALSE)
      ->addWhere('source_contact_id', '=', $contactID)
      ->addWhere('activity_type_id:name', '=', 'OptIn')
      ->execute());
  }

  /**
   * A contact with no opt in value is opted in and gets an activity changing from unset.
   */
  public function testUnsetContactIsOptedIn(): void {
    $email = 'unset-optin@example.org';
    $contactID = $this->createIndividual(['email_primary.email' => $email], 'unset');

    $this->optInViaPreferenceCenter($contactID, $email);

    $contact = Contact::get(FALSE)
      ->addWhere('id', '=', $contactID)
      ->setSelect(['Communication.opt_in'])
      ->execute()->first();
    $this->assertTrue($contact['Communication.opt_in']);
    $this->assertStringContainsString('opted in this primary email', $this->getActivity($contactID, 'OptIn')['details']);
  }

  /**
   * A contact with no opt in value is opted out and gets an activity changing from unset.
   */
  public function testUnsetContactIsOptedOut(): void {
    $email = 'unset-optout@example.org';
    $contactID = $this->createIndividual(['email_primary.email' => $email], 'unset');

    WMFContact::updateCommunicationsPreferences()
      ->setEmail($email)
      ->setContactID($contactID)
      ->setChecksum(\CRM_Contact_BAO_Contact_Utils::generateChecksum($contactID))
      ->setSendEmail('false')
      ->execute();

    $contact = Contact::get(FALSE)
      ->addWhere('id', '=', $contactID)
      ->setSelect(['Communication.opt_in'])
      ->execute()->first();
    $this->assertFalse($contact['Communication.opt_in']);
    $this->assertStringContainsString('opt_in from unset to 0', $this->getActivity($contactID, 'unsubscribe')['details']);
  }

  /**
   * Opting in from a double opt-in country sends the double opt-in email.
   */
  public function testOptInSendsDoubleOptInEmail(): void {
    $doubleOptInCountries = \Civi::settings()->get('thank_you_double_opt_in_countries');
    if (empty($doubleOptInCountries)) {
      $this->markTestSkipped('No countries configured for double opt-in');
    }
    $email = 'double-optin@example.org';
    $contactID = $this->createIndividual([
      'email_primary.email' => $email,
      'Communication.opt_in' => FALSE,
      'address_primary.country_id' => $doubleOptInCountries[0],
    ], 'double_opt_in');

    $this->optInViaPreferenceCenter($contactID, $email);

    $sentEmail = Activity::get(FALSE)
      ->addWhere('source_contact_id', '=', $contactID)
      ->addWhere('activity_type_id:name', '=', 'Email')
      ->addSelect('Email.Workflow')
      ->execute()->single();
    $this->assertEquals('double_opt_in', $sentEmail['Email.Workflow']);
  }

  private function optInViaPreferenceCenter(int $contactID, string $email): void {
    WMFContact::updateCommunicationsPreferences()
      ->setEmail($email)
      ->setContactID($contactID)
      ->setChecksum(\CRM_Contact_BAO_Contact_Utils::generateChecksum($contactID))
      ->setSendEmail('true')
      ->execute();
  }

  private function getActivity(int $contactID, string $type): array {
    return Activity::get(FALSE)
      ->addWhere('source_contact_id', '=', $contactID)
      ->addWhere('activity_type_id:name', '=', $type)
      ->execute()->single();
  }

}

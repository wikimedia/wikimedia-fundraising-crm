<?php

namespace Civi\Api4\WMFContact;

use Civi\API\Exception\UnauthorizedException;
use Civi\Api4\Activity;
use Civi\Api4\WMFContact;
use Civi\Api4\Email;
use PHPUnit\Framework\TestCase;
use Civi\Api4\Address;
use Civi\Api4\Contact;
use Civi\WMFEnvironmentTrait;

/**
 * This is a generic test class for the extension (implemented with PHPUnit).
 * @group epcV4
 **/
class UpdateCommunicationsPreferencesTest extends TestCase {
  use WMFEnvironmentTrait;

  protected $contactID;
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
      ->setSelect(['preferred_language', 'Communication.opt_in'])
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
}

<?php

namespace Civi\Api4\WMFContact;

use Civi\Api4\Activity;
use Civi\Api4\Contact;
use Civi\Api4\Email;
use Civi\Api4\WMFContact;
use Civi\Test\EntityTrait;
use Civi\WMFEnvironmentTrait;
use PHPUnit\Framework\TestCase;

/**
 * @group epcV4
 **/
class DoubleOptInTest extends TestCase {
  use WMFEnvironmentTrait;
  use EntityTrait;

  public function setUp(): void {
    parent::setUp();
    // Setting batch mode disables the snooze API call to Acoustic.
    \Civi::$statics['omnimail']['is_batch_snooze_update'] = TRUE;
  }

  public function tearDown(): void {
    unset(\Civi::$statics['omnimail']['is_batch_snooze_update']);
    parent::tearDown();
  }

  public function testDoubleOptInClearsOptOutFlagsButNotSnooze(): void {
    $snoozeDate = gmdate('Y-m-d', strtotime('+10 days'));
    $contactID = $this->createIndividual([
      'email_primary.email' => 'optin-clears@example.com',
      'email_primary.email_settings.snooze_date' => $snoozeDate,
      'is_opt_out' => TRUE,
      'do_not_email' => TRUE,
      'Communication.do_not_solicit' => TRUE,
      'Communication.opt_in' => FALSE,
    ]);
    $checksum = \CRM_Contact_BAO_Contact_Utils::generateChecksum($contactID);

    WMFContact::doubleOptIn(FALSE)
      ->setEmail('optin-clears@example.com')
      ->setContact_id($contactID)
      ->setChecksum($checksum)
      ->execute();

    $contact = Contact::get(FALSE)
      ->addSelect('is_opt_out', 'do_not_email', 'Communication.do_not_solicit', 'Communication.opt_in')
      ->addWhere('id', '=', $contactID)
      ->execute()->first();
    $this->assertFalse($contact['is_opt_out']);
    $this->assertFalse($contact['do_not_email']);
    $this->assertFalse($contact['Communication.do_not_solicit']);
    $this->assertTrue($contact['Communication.opt_in']);

    $email = Email::get(FALSE)
      ->addSelect('email_settings.snooze_date')
      ->addWhere('contact_id', '=', $contactID)
      ->addWhere('is_primary', '=', TRUE)
      ->execute()->first();
    $this->assertEquals($snoozeDate, $email['email_settings.snooze_date']);
  }

  public function testActivity(): void {
    $contactID = $this->createIndividual([
      'email_primary.email' => 'optin-activity@example.com',
    ]);
    $emailID = Email::get(FALSE)
      ->addWhere('contact_id', '=', $contactID)
      ->addWhere('is_primary', '=', TRUE)
      ->execute()->first()['id'];
    $checksum = \CRM_Contact_BAO_Contact_Utils::generateChecksum($contactID);

    $result = WMFContact::doubleOptIn(FALSE)
      ->setEmail('optin-activity@example.com')
      ->setContact_id($contactID)
      ->setChecksum($checksum)
      ->setCampaign('spring_campaign')
      ->setMedium('email')
      ->setSource('leadgen')
      ->execute();

    $activity = Activity::get(FALSE)
      ->addSelect('*', 'source_contact_id', 'target_contact_id', 'activity_tracking.activity_campaign', 'activity_tracking.activity_medium', 'activity_tracking.activity_source')
      ->addWhere('id', '=', $result->first()['id'])
      ->execute()->first();
    $this->assertEquals($emailID, $activity['source_record_id']);
    $this->assertEquals($contactID, $activity['source_contact_id']);
    $this->assertEquals([$contactID], $activity['target_contact_id']);
    $this->assertEquals('optin-activity@example.com', $activity['subject']);
    $this->assertEquals('spring_campaign', $activity['activity_tracking.activity_campaign']);
    $this->assertEquals('email', $activity['activity_tracking.activity_medium']);
    $this->assertEquals('leadgen', $activity['activity_tracking.activity_source']);
    $this->assertEquals(220, $activity['activity_type_id']);
  }

  public function testInvalidChecksumThrows(): void {
    $contactID = $this->createIndividual([
      'email_primary.email' => 'optin-badchecksum@example.com',
    ]);

    $this->expectException(\CRM_Core_Exception::class);
    WMFContact::doubleOptIn(FALSE)
      ->setEmail('optin-badchecksum@example.com')
      ->setContact_id($contactID)
      ->setChecksum('not-a-valid-checksum')
      ->execute();
  }

  public function testEmailNotPrimaryForContactThrows(): void {
    $contactID = $this->createIndividual([
      'email_primary.email' => 'optin-primary@example.com',
    ]);
    $checksum = \CRM_Contact_BAO_Contact_Utils::generateChecksum($contactID);

    $this->expectException(\CRM_Core_Exception::class);
    WMFContact::doubleOptIn(FALSE)
      ->setEmail('not-the-primary-email@example.com')
      ->setContact_id($contactID)
      ->setChecksum($checksum)
      ->execute();
  }

}

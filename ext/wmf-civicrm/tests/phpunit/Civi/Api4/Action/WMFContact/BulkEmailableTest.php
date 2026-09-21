<?php

namespace Civi\Api4\WMFContact;

use Civi\Api4\WMFContact;
use Civi\Api4\Contact;
use Civi\WMFEnvironmentTrait;
use Civi\Test\EntityTrait;
use PHPUnit\Framework\TestCase;
use Civi\Api4\Extension;

/**
 * @group epcV4
 **/
class BulkEmailableTest extends TestCase {
  use WMFEnvironmentTrait;
  use EntityTrait;

  protected function setUp(): void {
    parent::setUp();
    // Setting batch mode disables the snooze API call to Acoustic, preventing issues.
    \Civi::$statics['omnimail']['is_batch_snooze_update'] = TRUE;
  }

  public function testBulkEmailableEmailNotFound() {
    $this->expectException(\CRM_Core_Exception::class);
    WMFContact::bulkEmailable()
      ->setEmail('nonexistent@example.com')
      ->execute();
  }

  public function testBulkEmailableSuccess() {
    $contact = $this->createTestEntity('Contact', [
      'contact_type' => 'Individual',
    ]);
    $this->createTestEntity('Email', [
      'contact_id' => $contact['id'],
      'email' => 'test_bulk_emailable@example.com',
      'is_primary' => 1,
    ]);

    $result = WMFContact::bulkEmailable()
      ->setEmail('test_bulk_emailable@example.com')
      ->execute();

    $this->assertTrue($result->first());
  }

  /**
   * A non-primary copy of the address does not affect whether the address is emailable.
   */
  public function testBulkEmailableIgnoresSecondaryEmails() {
    $contactID = $this->createIndividual(['email_primary.email' => 'secondary@example.com'], 'emailable');
    $holderID = $this->createIndividual([
      'email_primary.email' => 'their_own@example.com',
      'is_opt_out' => TRUE,
    ], 'secondary_holder');
    $this->createTestEntity('Email', [
      'contact_id' => $holderID,
      'email' => 'secondary@example.com',
      'is_primary' => FALSE,
      'on_hold' => TRUE,
      'location_type_id:name' => 'Work',
      'email_settings.snooze_date' => date('Y-m-d', strtotime('+10 days')),
    ], 'secondary_email');

    $this->assertTrue(WMFContact::bulkEmailable()->setEmail('secondary@example.com')->execute()->first());
    $this->assertTrue(WMFContact::bulkEmailable()->setContactID($contactID)->execute()->first());
    // The holder's own primary is what decides their emailability.
    $this->assertFalse(WMFContact::bulkEmailable()->setContactID($holderID)->execute()->first());
  }

  public function testBulkEmailableContactIDOnly() {
    $contactID = $this->createIndividual(['email_primary.email' => 'contactonly@example.com'], 'emailable');
    $optedOutID = $this->createIndividual([
      'email_primary.email' => 'contactonly@example.com',
      'is_opt_out' => TRUE,
    ], 'opted_out');

    $this->assertTrue(WMFContact::bulkEmailable()->setContactID($contactID)->execute()->first());
    $this->assertFalse(WMFContact::bulkEmailable()->setContactID($optedOutID)->execute()->first());
    $this->assertFalse(WMFContact::bulkEmailable()->setEmail('contactonly@example.com')->execute()->first());
  }

  public function testBulkEmailableOptOut() {
    $contact = $this->createTestEntity('Contact', [
      'contact_type' => 'Individual',
      'is_opt_out' => 1,
    ]);
    $this->createTestEntity('Email', [
      'contact_id' => $contact['id'],
      'email' => 'optout@example.com',
      'is_primary' => 1,
    ]);

    $result = WMFContact::bulkEmailable()
      ->setEmail('optout@example.com')
      ->execute();

    $this->assertFalse($result->first());

    // Create a second contact with the same email who is NOT opted out
    $contact2 = $this->createTestEntity('Contact', [
      'contact_type' => 'Individual',
      'is_opt_out' => 0,
    ]);
    $this->createTestEntity('Email', [
      'contact_id' => $contact2['id'],
      'email' => 'optout@example.com',
      'is_primary' => 1,
    ]);

    $resultWithDuplicate = WMFContact::bulkEmailable()
      ->setEmail('optout@example.com')
      ->execute();

    $this->assertFalse($resultWithDuplicate->first(), 'Should still be false if ANY contact with that email is opted out');

    // Narrowing to one contact tells us which of them is the one blocking the address.
    $this->assertTrue(WMFContact::bulkEmailable()
      ->setEmail('optout@example.com')
      ->setContactID($contact2['id'])
      ->execute()->first());
    $this->assertFalse(WMFContact::bulkEmailable()
      ->setEmail('optout@example.com')
      ->setContactID($contact['id'])
      ->execute()->first());
  }

  public function testBulkEmailableOptInNo() {
    $contact = $this->createTestEntity('Contact', [
      'contact_type' => 'Individual',
      'Communication.opt_in' => FALSE,
    ]);
    $this->createTestEntity('Email', [
      'contact_id' => $contact['id'],
      'email' => 'optinno@example.com',
      'is_primary' => 1,
    ]);

    $result = WMFContact::bulkEmailable()
      ->setEmail('optinno@example.com')
      ->execute();

    $this->assertFalse($result->first());
  }

  public function testBulkEmailableSnoozed() {
    $contact = $this->createTestEntity('Contact', [
      'contact_type' => 'Individual',
    ]);
    $this->createTestEntity('Email', [
      'contact_id' => $contact['id'],
      'email' => 'snoozed@example.com',
      'email_settings.snooze_date' => gmdate('Y-m-d', strtotime('+1 day')),
      'is_primary' => 1,
    ]);

    $result = WMFContact::bulkEmailable()
      ->setEmail('snoozed@example.com')
      ->setCheckSnooze(TRUE)
      ->execute();
    $this->assertFalse($result->first(), 'Should be false when snoozed and checkSnooze is TRUE');

    $resultIgnoreSnooze = WMFContact::bulkEmailable()
      ->setEmail('snoozed@example.com')
      ->setCheckSnooze(FALSE)
      ->execute();
    $this->assertTrue($resultIgnoreSnooze->first(), 'Should be true when snoozed but checkSnooze is FALSE');
  }

  public function testBulkEmailableNoPrimaryOrDeleted() {
    $contact = $this->createTestEntity('Contact', [
      'contact_type' => 'Individual',
    ]);
    $this->createTestEntity('Email', [
      'contact_id' => $contact['id'],
      'email' => 'primary@example.com',
      'is_primary' => 1,
    ]);
    $this->createTestEntity('Email', [
      'contact_id' => $contact['id'],
      'email' => 'nonprimary@example.com',
      'is_primary' => 0,
    ]);

    $result = WMFContact::bulkEmailable()
      ->setEmail('nonprimary@example.com')
      ->execute();

    $this->assertFalse($result->first(), 'Should return false if no primary email matches address');

    // Test when the email is primary for another contact, but the contact is deleted
    $contact2 = $this->createTestEntity('Contact', [
      'contact_type' => 'Individual',
    ]);
    $this->createTestEntity('Email', [
      'contact_id' => $contact2['id'],
      'email' => 'nonprimary@example.com',
      'is_primary' => 1,
    ]);

    Contact::delete(FALSE)
      ->addWhere('id', '=', $contact2['id'])
      ->execute();

    $resultDeleted = WMFContact::bulkEmailable()
      ->setEmail('nonprimary@example.com')
      ->execute();

    $this->assertFalse($resultDeleted->first(), 'Should return false if the primary email contact is deleted');
  }

  protected function tearDown(): void {
    unset(\Civi::$statics['omnimail']['is_batch_snooze_update']);
    parent::tearDown();
  }

}

<?php

namespace Civi\Api4\WMFContact;

use Civi\Api4\Contact;
use Civi\Api4\Email;
use Civi\Api4\WMFContact;
use Civi\Test\EntityTrait;
use Civi\WMFEnvironmentTrait;
use PHPUnit\Framework\TestCase;

/**
 * @group epcV4
 **/
class OptInTest extends TestCase {
  use WMFEnvironmentTrait;
  use EntityTrait;

  private string $email = 'optin-test@example.org';

  private string $snoozeDate;

  public function setUp(): void {
    parent::setUp();
    // Setting batch mode disables the snooze API call to Acoustic.
    \Civi::$statics['omnimail']['is_batch_snooze_update'] = TRUE;
    $this->snoozeDate = date('Y-m-d', strtotime('+10 days'));
  }

  public function tearDown(): void {
    unset(\Civi::$statics['omnimail']['is_batch_snooze_update']);
    parent::tearDown();
  }

  public function testOptInAppliesToAllContactsWithPrimaryEmail(): void {
    $firstID = $this->createOptedOutContact('first');
    $secondID = $this->createOptedOutContact('second');

    WMFContact::optIn(FALSE)->setEmail($this->email)->execute();

    $this->assertOptedIn($firstID);
    $this->assertOptedIn($secondID);
  }

  public function testOptInReleasesHoldButKeepsSnoozeForNonPrimaryEmailsWithSameAddress(): void {
    $primaryID = $this->createOptedOutContact('primary');
    $otherID = $this->createOptedOutContact('other', ['email_primary.email' => 'other@example.org']);
    $this->createHeldSecondaryEmail($otherID);

    WMFContact::optIn(FALSE)->setEmail($this->email)->execute();

    $this->assertOptedIn($primaryID);
    $this->assertOptedOut($otherID);
    $secondary = $this->getSecondaryEmail($otherID);
    $this->assertEquals(0, $secondary['on_hold']);
    $this->assertEquals($this->snoozeDate, $secondary['email_settings.snooze_date']);
  }

  public function testOptInWithoutCheckSnoozeClearsOnHoldButKeepsSnoozeDate(): void {
    $contactID = $this->createOptedOutContact('contact');

    WMFContact::optIn(FALSE)->setEmail($this->email)->setCheckSnooze(FALSE)->execute();

    $contact = $this->getContact($contactID);
    $this->assertTrue($contact['Communication.opt_in']);
    $this->assertEquals(0, $contact['email_primary.on_hold']);
    $this->assertEquals($this->snoozeDate, $contact['email_primary.email_settings.snooze_date']);
  }

  public function testOptInWithContactIDUpdatesOnlyThatContact(): void {
    $contactID = $this->createOptedOutContact('contact');
    $otherID = $this->createOptedOutContact('other');

    WMFContact::optIn(FALSE)->setEmail($this->email)->setContactID($contactID)->execute();

    $this->assertOptedIn($contactID);
    $this->assertOptedOut($otherID);
  }

  public function testOptInWithContactIDUpdatesThatContact(): void {
    $contactID = $this->createOptedOutContact('contact');
    $otherID = $this->createOptedOutContact('other');

    WMFContact::optIn(FALSE)->setContactID($contactID)->execute();

    $this->assertOptedIn($contactID);
    $this->assertOptedOut($otherID);
  }

  public function testOptInWithContactIDDoesNotUpdateOtherContactsNonPrimaryEmails(): void {
    $contactID = $this->createOptedOutContact('contact');
    $otherID = $this->createOptedOutContact('other', ['email_primary.email' => 'other@example.org']);
    $this->createHeldSecondaryEmail($otherID);

    WMFContact::optIn(FALSE)->setEmail($this->email)->setContactID($contactID)->execute();

    $this->assertOptedIn($contactID);
    $secondary = $this->getSecondaryEmail($otherID);
    $this->assertEquals(1, $secondary['on_hold']);
    $this->assertEquals($this->snoozeDate, $secondary['email_settings.snooze_date']);
  }

  public function testOptInSetsOptInWhenNull(): void {
    $contactID = $this->createIndividual(['email_primary.email' => $this->email], 'null_opt_in');
    $this->assertNull($this->getContact($contactID)['Communication.opt_in']);

    WMFContact::optIn(FALSE)->setEmail($this->email)->execute();

    $this->assertTrue($this->getContact($contactID)['Communication.opt_in']);
  }

  public function testOptInRequiresEmailOrContactID(): void {
    $contactID = $this->createOptedOutContact('contact');

    try {
      WMFContact::optIn(FALSE)->execute();
      $this->fail('Expected an exception when neither email nor contactID is given.');
    }
    catch (\CRM_Core_Exception $e) {
      $this->assertEquals('Either email or contactID is required.', $e->getMessage());
    }

    $this->assertOptedOut($contactID);
  }

  private function createOptedOutContact(string $identifier, array $params = []): int {
    return $this->createIndividual(array_merge([
      'email_primary.email' => $this->email,
      'email_primary.on_hold' => TRUE,
      'email_primary.email_settings.snooze_date' => $this->snoozeDate,
      'Communication.opt_in' => FALSE,
      'is_opt_out' => TRUE,
      'do_not_email' => TRUE,
      'Communication.do_not_solicit' => TRUE,
    ], $params), $identifier);
  }

  private function createHeldSecondaryEmail(int $contactID): void {
    $this->createTestEntity('Email', [
      'contact_id' => $contactID,
      'email' => $this->email,
      'is_primary' => FALSE,
      'on_hold' => TRUE,
      'location_type_id:name' => 'Work',
      'email_settings.snooze_date' => $this->snoozeDate,
    ], 'secondary_email');
  }

  private function getSecondaryEmail(int $contactID): array {
    return Email::get(FALSE)
      ->addSelect('on_hold', 'email_settings.snooze_date')
      ->addWhere('contact_id', '=', $contactID)
      ->addWhere('email', '=', $this->email)
      ->execute()->first();
  }

  private function getContact(int $contactID): array {
    return Contact::get(FALSE)
      ->addSelect(
        'Communication.opt_in',
        'is_opt_out',
        'do_not_email',
        'Communication.do_not_solicit',
        'email_primary.on_hold',
        'email_primary.email_settings.snooze_date'
      )
      ->addWhere('id', '=', $contactID)
      ->execute()->first();
  }

  private function assertOptedIn(int $contactID): void {
    $contact = $this->getContact($contactID);
    $this->assertTrue($contact['Communication.opt_in']);
    $this->assertFalse($contact['is_opt_out']);
    $this->assertFalse($contact['do_not_email']);
    $this->assertFalse($contact['Communication.do_not_solicit']);
    $this->assertEquals(0, $contact['email_primary.on_hold']);
    $this->assertEquals(date('Y-m-d', strtotime('+1 day')), $contact['email_primary.email_settings.snooze_date']);
  }

  private function assertOptedOut(int $contactID): void {
    $contact = $this->getContact($contactID);
    $this->assertFalse($contact['Communication.opt_in']);
    $this->assertTrue($contact['is_opt_out']);
    $this->assertTrue($contact['do_not_email']);
    $this->assertTrue($contact['Communication.do_not_solicit']);
    $this->assertEquals(1, $contact['email_primary.on_hold']);
    $this->assertEquals($this->snoozeDate, $contact['email_primary.email_settings.snooze_date']);
  }

}

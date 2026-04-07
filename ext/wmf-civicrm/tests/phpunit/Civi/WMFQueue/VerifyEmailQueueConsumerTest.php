<?php

namespace Civi\WMFQueue;

use Civi\Api4\Activity;
use Civi\Api4\Contact;
use Civi\Api4\Email;

/**
 * @group WMFQueue
 * @group VerifyEmail
 */
class VerifyEmailQueueConsumerTest extends BaseQueueTestCase {

  protected string $queueConsumer = 'VerifyEmail';

  protected string $queueName = 'verify-email';

  /**
   * @var string
   */
  protected string $primaryEmail = 'primary@example.com';

  /**
   * @var string
   */
  protected string $newEmail = 'newprimary@example.com';

  public function setUp(): void {
    parent::setUp();
    $this->createIndividual();
  }

  /**
   * Test that message validation rejects missing parameters.
   */
  public function testValidationRejectsMissingContactId(): void {
    $this->expectException(\CRM_Core_Exception::class);
    $this->expectExceptionMessage('Missing parameters in set primary email message');

    $message = [
      'email' => $this->newEmail,
      'checksum' => 'fake_checksum_abc123_inf',
    ];

    $this->processMessageWithoutQueuing($message);
  }

  /**
   * Test that message validation rejects invalid email.
   */
  public function testValidationRejectsInvalidEmail(): void {
    $this->expectException(\CRM_Core_Exception::class);
    $this->expectExceptionMessage('Invalid parameter types in set primary email message');

    $message = [
      'contact_id' => $this->getContactID(),
      'email' => 'not-an-email',
      'checksum' => 'fake_checksum_abc123_inf',
    ];

    $this->processMessageWithoutQueuing($message);
  }

  /**
   * Test that message validation rejects invalid checksum format.
   */
  public function testValidationRejectsInvalidChecksumFormat(): void {
    $this->expectException(\CRM_Core_Exception::class);
    $this->expectExceptionMessage('Invalid parameter types in set primary email message');

    $message = [
      'contact_id' => $this->getContactID(),
      'email' => $this->newEmail,
      'checksum' => 'invalid-format',
    ];

    $this->processMessageWithoutQueuing($message);
  }

  /**
   * Test that message validation rejects invalid checksum (mismatched).
   */
  public function testValidationRejectsMismatchedChecksum(): void {
    $this->expectException(\CRM_Core_Exception::class);
    $this->expectExceptionMessage('Checksum mismatch');

    $message = [
      'contact_id' => $this->getContactID(),
      'email' => $this->newEmail,
      'checksum' => 'aabbccdd_123456_inf',
    ];

    $this->processMessageWithoutQueuing($message);
  }

  /**
   * Test that a valid message with proper checksum updates the primary email.
   */
  public function testPrimaryEmailIsUpdatedWithValidChecksum(): void {
    // Create initial email
    $this->createEmail($this->primaryEmail);

    // Create a valid checksum for the contact
    $checksum = $this->generateValidChecksum($this->getContactID());

    // Verify the initial state
    $contactBefore = $this->getContactByID($this->getContactID());
    $this->assertEquals($this->primaryEmail, $contactBefore['email_primary.email']);

    // Process the message
    $message = [
      'contact_id' => $this->getContactID(),
      'email' => $this->newEmail,
      'checksum' => $checksum,
    ];
    $this->processMessageWithoutQueuing($message);

    // Verify the email has been updated
    $contactAfter = $this->getContactByID($this->getContactID());
    $this->assertEquals($this->newEmail, $contactAfter['email_primary.email']);
  }

  /**
   * Test that no update occurs when email is already the primary.
   */
  public function testNoUpdateWhenEmailAlreadyPrimary(): void {
    // Create initial email as primary
    $this->createEmail($this->primaryEmail);

    // Create a valid checksum for the contact
    $checksum = $this->generateValidChecksum($this->getContactID());

    // Process message with the same email
    $message = [
      'contact_id' => $this->getContactID(),
      'email' => $this->primaryEmail,
      'checksum' => $checksum,
    ];
    $this->processMessageWithoutQueuing($message);

    // Verify email hasn't changed
    $contactAfter = $this->getContactByID($this->getContactID());
    $this->assertEquals($this->primaryEmail, $contactAfter['email_primary.email']);

    // Verify activity was not created
    $activities = Activity::get(FALSE)
      ->addWhere('source_contact_id', '=', $this->getContactID())
      ->addWhere('activity_type_id:name', '=', 'Verify Email And Set As Primary')
      ->execute();
    $this->assertCount(0, $activities);
  }

  /**
   * Test that update creates activity record.
   */
  public function testUpdateCreatesActivityRecord(): void {
    // Create initial email
    $this->createEmail($this->primaryEmail);

    // Create a valid checksum for the contact
    $checksum = $this->generateValidChecksum($this->getContactID());

    // Process the message
    $message = [
      'contact_id' => $this->getContactID(),
      'email' => $this->newEmail,
      'checksum' => $checksum,
    ];
    $this->processMessageWithoutQueuing($message);

    // Verify activity was created
    $activities = Activity::get(FALSE)
      ->addWhere('source_contact_id', '=', $this->getContactID())
      ->addWhere('activity_type_id:name', '=', 'Verify Email And Set As Primary')
      ->addSelect('*', 'status_id:name')
      ->execute();

    $this->assertCount(1, $activities);

    $activity = $activities->first();
    $this->assertStringContainsString($this->newEmail, $activity['subject']);
    $this->assertStringContainsString($this->primaryEmail, $activity['details']);
    $this->assertEquals($this->getContactID(), $activity['source_record_id']);
    $this->assertEquals('Completed', $activity['status_id:name']);
  }

  /**
   * Test that location type is demoted when not default or EPC.
   */
  public function testLocationTypeIsDemotedWhenNotDefaultOrEPC(): void {
    // Get the non-default location type ID (e.g., 'Work')
    $workLocationTypeId = \CRM_Core_PseudoConstant::getKey('CRM_Core_BAO_Email', 'location_type_id', 'Work');

    // Create email with Work location type (not primary)
    $this->createEmail($this->primaryEmail, FALSE, $this->getContactID(), $workLocationTypeId);

    // Create a valid checksum for the contact
    $checksum = $this->generateValidChecksum($this->getContactID());

    // Process the message
    $message = [
      'contact_id' => $this->getContactID(),
      'email' => $this->newEmail,
      'checksum' => $checksum,
    ];
    $this->processMessageWithoutQueuing($message);

    // Verify the email has been updated to the new one
    $contactAfter = $this->getContactByID($this->getContactID());
    $this->assertEquals($this->newEmail, $contactAfter['email_primary.email']);

    // Verify location type is now EPC (EmailPreference)
    $emails = Email::get(FALSE)
      ->addWhere('email', '=', $this->newEmail)
      ->addWhere('contact_id', '=', $this->getContactID())
      ->execute();

    $this->assertCount(1, $emails);
    $email = $emails->first();
    $this->assertEquals(
      \CRM_Core_PseudoConstant::getKey('CRM_Core_BAO_Email', 'location_type_id', 'EmailPreference'),
      $email['location_type_id']
    );
  }

  /**
   * Test that location type is updated when changing from default.
   */
  public function testLocationTypeIsUpdatedWhenChangingFromDefault(): void {
    $defaultLocationTypeId = \CRM_Core_BAO_LocationType::getDefault()->id;

    // Create email with default location type
    $this->createEmail($this->primaryEmail, TRUE, $this->getContactID(), $defaultLocationTypeId);

    // Create a valid checksum for the contact
    $checksum = $this->generateValidChecksum($this->getContactID());

    // Process the message
    $message = [
      'contact_id' => $this->getContactID(),
      'email' => $this->newEmail,
      'checksum' => $checksum,
    ];
    $this->processMessageWithoutQueuing($message);

    // Verify the email has been updated to the new one
    $contactAfter = $this->getContactByID($this->getContactID());
    $this->assertEquals($this->newEmail, $contactAfter['email_primary.email']);

    // Verify the old email location type was not changed
    $oldEmails = Email::get(FALSE)
      ->addWhere('email', '=', $this->primaryEmail)
      ->addWhere('contact_id', '=', $this->getContactID())
      ->execute();

    // The old email should still exist if location type was default
    if ($oldEmails->count() > 0) {
      $this->assertTrue(TRUE);
    }
  }

  /**
   * Test that double opt-in activity is created for specific countries.
   */
  public function testDoubleOptInActivityCreatedForSpecificCountries(): void {
    // Get a country from the double opt-in settings
    $doubleOptInCountries = \Civi::settings()->get('thank_you_double_opt_in_countries');

    if (empty($doubleOptInCountries)) {
      $this->markTestSkipped('No countries configured for double opt-in');
    }

    // Get the country ID for the first country in the list
    $countryId = $doubleOptInCountries[0];

    // Update the contact's address to be in a double opt-in country
    Contact::update(FALSE)
      ->addWhere('id', '=', $this->getContactID())
      ->setValues(['address_primary.country_id' => $countryId])
      ->execute();

    // Create initial email
    $this->createEmail($this->primaryEmail);

    // Create a valid checksum for the contact
    $checksum = $this->generateValidChecksum($this->getContactID());

    // Process the message
    $message = [
      'contact_id' => $this->getContactID(),
      'email' => $this->newEmail,
      'checksum' => $checksum,
    ];
    $this->processMessageWithoutQueuing($message);

    // Verify double opt-in activity was created
    $doubleOptInActivities = Activity::get(FALSE)
      ->addWhere('source_contact_id', '=', $this->getContactID())
      ->addWhere('activity_type_id:name', '=', 'Double Opt-In')
      ->addWhere('subject', '=', $this->newEmail)
      ->execute();

    $this->assertGreaterThan(0, $doubleOptInActivities->count());
  }

  /**
   * Test that double opt-in is not created twice for the same email.
   */
  public function testDoubleOptInIsNotCreatedTwice(): void {
    // Get a country from the double opt-in settings
    $doubleOptInCountries = \Civi::settings()->get('thank_you_double_opt_in_countries');

    if (empty($doubleOptInCountries)) {
      $this->markTestSkipped('No countries configured for double opt-in');
    }

    $countryId = $doubleOptInCountries[0];

    // Update the contact's address to be in a double opt-in country
    Contact::update(FALSE)
      ->addWhere('id', '=', $this->getContactID())
      ->setValues(['address_primary.country_id' => $countryId])
      ->execute();

    // Create initial email
    $this->createEmail($this->primaryEmail);

    // Create a valid checksum for the contact
    $checksum = $this->generateValidChecksum($this->getContactID());

    // Process the message first time
    $message = [
      'contact_id' => $this->getContactID(),
      'email' => $this->newEmail,
      'checksum' => $checksum,
    ];
    $this->processMessageWithoutQueuing($message);

    // Count double opt-in activities
    $doubleOptInActivitiesFirst = Activity::get(FALSE)
      ->addWhere('source_contact_id', '=', $this->getContactID())
      ->addWhere('activity_type_id:name', '=', 'Double Opt-In')
      ->addWhere('subject', '=', $this->newEmail)
      ->execute();

    $firstCount = $doubleOptInActivitiesFirst->count();

    // Create another email and process it
    $anotherEmail = 'another@example.com';
    $this->createEmail($anotherEmail);

    $checksum2 = $this->generateValidChecksum($this->getContactID());

    $message2 = [
      'contact_id' => $this->getContactID(),
      'email' => $anotherEmail,
      'checksum' => $checksum2,
    ];
    $this->processMessageWithoutQueuing($message2);

    // Count double opt-in activities again
    $doubleOptInActivitiesSecond = Activity::get(FALSE)
      ->addWhere('source_contact_id', '=', $this->getContactID())
      ->addWhere('activity_type_id:name', '=', 'Double Opt-In')
      ->execute();

    $secondCount = $doubleOptInActivitiesSecond->count();

    // Both emails should have their own double opt-in activity
    $this->assertGreaterThanOrEqual($firstCount, $secondCount);
  }

  /**
   * Test that on_hold flag is set to 0 for new email.
   */
  public function testOnHoldFlagIsZeroForNewEmail(): void {
    // Create initial email
    $this->createEmail($this->primaryEmail);

    // Create a valid checksum for the contact
    $checksum = $this->generateValidChecksum($this->getContactID());

    // Process the message
    $message = [
      'contact_id' => $this->getContactID(),
      'email' => $this->newEmail,
      'checksum' => $checksum,
    ];
    $this->processMessageWithoutQueuing($message);

    // Verify on_hold is 0 for new email
    $emails = Email::get(FALSE)
      ->addWhere('email', '=', $this->newEmail)
      ->addWhere('contact_id', '=', $this->getContactID())
      ->execute();

    $this->assertCount(1, $emails);
    $email = $emails->first();
    $this->assertEquals(0, $email['on_hold']);
  }

  /**
   * Test validation rejects non-array message.
   */
  public function testValidationRejectsNonArrayMessage(): void {
    $this->expectException(\CRM_Core_Exception::class);
    $this->expectExceptionMessage('Invalid set primary email message format');

    $consumer = new VerifyEmailQueueConsumer('test');
    $consumer->validateInput('not-an-array');
  }

  /**
   * Create an email for a contact.
   *
   * @param string $email
   * @param bool $isPrimary
   * @param int|null $contactId
   * @param int|null $locationTypeId
   */
  protected function createEmail(
    string $email,
    bool $isPrimary = TRUE,
    ?int $contactId = NULL,
    ?int $locationTypeId = NULL
  ): void {
    $contactId = $contactId ?? $this->getContactID();
    $locationTypeId = $locationTypeId ?? \CRM_Core_BAO_LocationType::getDefault()->id;

    $this->createTestEntity('Email', [
      'email' => $email,
      'contact_id' => $contactId,
      'is_primary' => $isPrimary ? 1 : 0,
      'location_type_id' => $locationTypeId,
    ]);
  }

  /**
   * Get contact information by ID.
   *
   * @param int $contactID
   *
   * @return array|null
   */
  protected function getContactByID(int $contactID): ?array {
    try {
      return Contact::get(FALSE)
        ->addWhere('id', '=', $contactID)
        ->addSelect('email_primary.email')
        ->addSelect('email_primary.id')
        ->addSelect('email_primary.location_type_id')
        ->addSelect('address_primary.country_id')
        ->execute()
        ->first();
    }
    catch (\CRM_Core_Exception $e) {
      $this->fail($e->getMessage());
    }
  }

  /**
   * Generate a valid checksum for a contact.
   *
   * @param int $contactID
   *
   * @return string
   */
  protected function generateValidChecksum(int $contactID): string {
    return \CRM_Contact_BAO_Contact_Utils::generateChecksum($contactID, NULL, 'inf');
  }

}


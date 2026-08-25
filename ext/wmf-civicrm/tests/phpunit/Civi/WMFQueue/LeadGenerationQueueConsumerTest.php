<?php

namespace Civi\WMFQueue;

use Civi\Api4\Activity;
use Civi\Api4\Contact;
use Civi\Api4\Email;
use Civi\WMFException\WMFException;
use SmashPig\Core\UtcDate;

/**
 * @group WMFQueue
 */
class LeadGenerationQueueConsumerTest extends BaseQueueTestCase {

  protected string $queueConsumer = 'LeadGeneration';

  protected string $queueName = 'lead-generation';

  protected string $email = 'mouse@wikimedia.org';

  public function setUp(): void {
    parent::setUp();
    // Setting batch mode disables the snooze API call to Acoustic.
    \Civi::$statics['omnimail']['is_batch_snooze_update'] = TRUE;
  }

  public function tearDown(): void {
    unset(\Civi::$statics['omnimail']['is_batch_snooze_update']);
    parent::tearDown();
  }

  protected function getMessage(array $values = []): array {
    return $values + [
      'email' => $this->email,
      'leadgen_source' => 'wikipedia_banner',
      'source_enqueued_time' => 1755561600,
    ];
  }

  protected function getContactsForEmail(): array {
    return (array) Contact::get(FALSE)
      ->addSelect('id', 'source', 'Communication.opt_in')
      ->addWhere('email_primary.email', '=', $this->email)
      ->addWhere('is_deleted', '=', 0)
      ->execute()->indexBy('id');
  }

  protected function getLeadGenerationActivities(int $contactID): array {
    return (array) Activity::get(FALSE)
      ->addSelect('*', 'status_id:name', 'target_contact_id', 'source_contact_id', 'Source.Source')
      ->addWhere('activity_type_id:name', '=', LeadGenerationQueueConsumer::ACTIVITY_TYPE_NAME)
      ->addWhere('target_contact_id', 'CONTAINS', $contactID)
      ->execute();
  }

  protected function getDoubleOptInEmailActivities(int $contactID): array {
    return (array) Activity::get(FALSE)
      ->addSelect('*', 'status_id:name', 'target_contact_id', 'source_contact_id')
      ->addWhere('activity_type_id:name', '=', 'Email')
      ->addWhere('target_contact_id', 'CONTAINS', $contactID)
      ->execute();
  }

  /**
   * A contact is created and has a source added when the email is not known.
   *
   * It is not opted in - opt in is pending confirmation via the double opt-in email.
   */
  public function testNewContactIsCreated(): void {
    $this->processMessageWithoutQueuing($this->getMessage());

    $contacts = $this->getContactsForEmail();
    $this->assertCount(1, $contacts);
    $contact = reset($contacts);
    $this->assertEquals('Leadgen: wikipedia_banner', $contact['source']);
    $this->assertFalse($contact['Communication.opt_in']);
  }

  /**
   * A brand new contact always needs to confirm via double opt-in.
   */
  public function testNewContactTriggersDoubleOptInEmail(): void {
    $this->processMessageWithoutQueuing($this->getMessage());

    $contacts = $this->getContactsForEmail();
    $contactID = key($contacts);

    $this->assertEquals(1, $this->getMailingCount());
    $mailing = $this->getMailing(0);
    $this->assertEquals($this->email, $mailing['to_address']);

    $emailActivities = $this->getDoubleOptInEmailActivities($contactID);
    $this->assertCount(1, $emailActivities);
    $this->assertEquals('Template: double_opt_in_lead_gen', reset($emailActivities)['details']);
  }

  public function testActivityIsCreated(): void {
    $this->processMessageWithoutQueuing($this->getMessage());

    $contacts = $this->getContactsForEmail();
    $contactID = key($contacts);

    $activities = $this->getLeadGenerationActivities($contactID);
    $this->assertCount(1, $activities);
    $activity = reset($activities);
    $this->assertEquals('Lead generation signup from wikipedia_banner', $activity['subject']);
    $this->assertEquals(
      "The email address {$this->email} was entered into a lead generation form with source wikipedia_banner.",
      $activity['details']
    );
    $this->assertEquals('Completed', $activity['status_id:name']);
    $this->assertEquals('wikipedia_banner', $activity['Source.Source']);
    $this->assertEquals([$contactID], $activity['target_contact_id']);
    $this->assertEquals($contactID, $activity['source_contact_id']);
  }

  /**
   * An existing, already-bulk-emailable contact is not sent a double opt-in email
   */
  public function testExistingBulkEmailableContactOptInIsUnchanged(): void {
    $contactID = $this->createIndividual([
      'email_primary.email' => $this->email,
      'Communication.opt_in' => TRUE,
    ]);

    $this->processMessageWithoutQueuing($this->getMessage());

    $contacts = $this->getContactsForEmail();
    $this->assertCount(1, $contacts);
    $this->assertTrue($contacts[$contactID]['Communication.opt_in']);
    // The source of an existing contact is left alone.
    $this->assertEquals('', $contacts[$contactID]['source']);

    $this->assertEquals(0, $this->getMailingCount());

    $activities = $this->getLeadGenerationActivities($contactID);
    $this->assertCount(1, $activities);
    $this->assertEquals([$contactID], reset($activities)['target_contact_id']);
  }

  /**
   * An existing contact who is opted out is sent a double opt-in email.
   */
  public function testExistingNonBulkEmailableContactTriggersDoubleOptInEmail(): void {
    $contactID = $this->createIndividual([
      'email_primary.email' => $this->email,
      'Communication.opt_in' => FALSE,
    ]);

    $this->processMessageWithoutQueuing($this->getMessage());

    $this->assertEquals(1, $this->getMailingCount());
    $emailActivities = $this->getDoubleOptInEmailActivities($contactID);
    $this->assertCount(1, $emailActivities);
  }

  /**
   * All contacts sharing the email are targeted by the signup activity.
   */
  public function testAllContactsWithEmailAreTargetedByActivity(): void {
    $firstContactID = $this->createIndividual(['email_primary.email' => $this->email]);
    $secondContactID = $this->createIndividual(['email_primary.email' => $this->email], 'second_mouse');

    $this->processMessageWithoutQueuing($this->getMessage());

    $contacts = $this->getContactsForEmail();
    $this->assertCount(2, $contacts);

    $activities = $this->getLeadGenerationActivities($firstContactID);
    $this->assertCount(1, $activities);
    $activity = reset($activities);
    $this->assertEquals([$firstContactID, $secondContactID], $activity['target_contact_id']);
    $this->assertEquals($firstContactID, $activity['source_contact_id']);
  }

  /**
   * Only one double opt-in email is sent when contacts sharing the email have mixed opt-in status.
   */
  public function testMixedOptInStatusContactsWithEmailSendsOneDoubleOptInEmail(): void {
    $this->createIndividual([
      'email_primary.email' => $this->email,
      'Communication.opt_in' => TRUE,
    ]);
    $this->createIndividual([
      'email_primary.email' => $this->email,
      'Communication.opt_in' => FALSE,
    ], 'second_mouse');

    $this->processMessageWithoutQueuing($this->getMessage());

    $this->assertEquals(1, $this->getMailingCount());
  }

  /**
   * A snooze on a contact's primary email is changed to tomorrow.
   */
  public function testExistingBulkEmailableContactDistantSnoozeIsShortened(): void {
    $contactID = $this->createIndividual([
      'email_primary.email' => $this->email,
      'email_primary.email_settings.snooze_date' => gmdate('Y-m-d', strtotime('+10 days')),
    ]);

    $this->processMessageWithoutQueuing($this->getMessage());

    $snoozeDate = Email::get(FALSE)
      ->addSelect('email_settings.snooze_date')
      ->addWhere('contact_id', '=', $contactID)
      ->addWhere('is_primary', '=', TRUE)
      ->execute()->first()['email_settings.snooze_date'];
    $this->assertEquals(gmdate('Y-m-d', strtotime('+1 day')), gmdate('Y-m-d', strtotime($snoozeDate)));
  }

}

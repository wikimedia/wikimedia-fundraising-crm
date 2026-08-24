<?php

namespace Civi\WMFQueue;

use Civi\Api4\Activity;
use Civi\Api4\Contact;
use Civi\WMFException\WMFException;
use SmashPig\Core\UtcDate;

/**
 * @group WMFQueue
 */
class LeadGenerationQueueConsumerTest extends BaseQueueTestCase {

  protected string $queueConsumer = 'LeadGeneration';

  protected string $queueName = 'lead-generation';

  protected string $email = 'mouse@wikimedia.org';

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

  /**
   * A contact is created, opted in and has a source added when the email is not known.
   */
  public function testNewContactIsCreated(): void {
    $this->processMessageWithoutQueuing($this->getMessage());

    $contacts = $this->getContactsForEmail();
    $this->assertCount(1, $contacts);
    $contact = reset($contacts);
    $this->assertEquals('Leadgen: wikipedia_banner', $contact['source']);
    $this->assertTrue($contact['Communication.opt_in']);
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
   * An existing contact is opted in rather than duplicated.
   */
  public function testExistingContactIsOptedIn(): void {
    $contactID = $this->createIndividual(['email_primary.email' => $this->email]);

    $this->processMessageWithoutQueuing($this->getMessage());

    $contacts = $this->getContactsForEmail();
    $this->assertCount(1, $contacts);
    $this->assertTrue($contacts[$contactID]['Communication.opt_in']);
    // The source of an existing contact is left alone.
    $this->assertEquals('', $contacts[$contactID]['source']);

    $activities = $this->getLeadGenerationActivities($contactID);
    $this->assertCount(1, $activities);
    $this->assertEquals([$contactID], reset($activities)['target_contact_id']);
  }

  /**
   * All contacts sharing the email are opted in and targeted by the activity.
   */
  public function testAllContactsWithEmailAreOptedIn(): void {
    $firstContactID = $this->createIndividual(['email_primary.email' => $this->email]);
    $secondContactID = $this->createIndividual(['email_primary.email' => $this->email], 'second_mouse');

    $this->processMessageWithoutQueuing($this->getMessage());

    $contacts = $this->getContactsForEmail();
    $this->assertCount(2, $contacts);
    $this->assertTrue($contacts[$firstContactID]['Communication.opt_in']);
    $this->assertTrue($contacts[$secondContactID]['Communication.opt_in']);

    $activities = $this->getLeadGenerationActivities($firstContactID);
    $this->assertCount(1, $activities);
    $activity = reset($activities);
    $this->assertEquals([$firstContactID, $secondContactID], $activity['target_contact_id']);
    $this->assertEquals($firstContactID, $activity['source_contact_id']);
  }
}

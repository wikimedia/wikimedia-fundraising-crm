<?php

namespace Civi\WMFQueue;

use Civi\Api4\Action\WorkflowMessage\Render;
use Civi\Api4\Activity;
use Civi\WorkflowMessage\NewChecksumLinkMessage;
use Civi\WorkflowMessage\WorkflowMessage;

/**
 * @group WMFQueue
 * @group NewChecksumLink
 */
class NewChecksumLinkQueueConsumerTest extends BaseQueueTestCase {

  protected string $queueConsumer = 'NewChecksumLink';

  protected string $queueName = 'new-checksum-link';

  private string $primaryEmail = 'primary@example.com';

  private string $secondaryEmail = 'secondary@example.com';

  /**
   * Values passed to each WorkflowMessage render during the test.
   *
   * @var array
   */
  private array $renderedValues = [];

  /**
   * @var callable|null
   */
  private $renderListener;

  public function setUp(): void {
    parent::setUp();
    $this->createIndividual(['email_primary.email' => $this->primaryEmail]);
    $this->listenForRenderedValues();
  }

  public function tearDown(): void {
    if ($this->renderListener) {
      \Civi::dispatcher()->removeListener('civi.api.prepare', $this->renderListener);
      $this->renderListener = NULL;
    }
    parent::tearDown();
  }

  /**
   * Record the values the consumer passes to the workflow message.
   *
   * The message template itself is edited on production, so its wording will
   * drift from the copy in this extension. We check the values we hand the
   * template rather than the text it renders.
   */
  private function listenForRenderedValues(): void {
    $this->renderListener = function ($event) {
      $request = $event->getApiRequest();
      if ($request instanceof Render) {
        $this->renderedValues[] = $request->getValues();
      }
    };
    \Civi::dispatcher()->addListener('civi.api.prepare', $this->renderListener);
  }

  /**
   * Get the template parameters of one rendered email.
   *
   * These are built the same way the render action builds them, so class
   * defaults are applied just as they are when the email goes out.
   *
   * @param int $index
   *
   * @return array
   */
  private function getTemplateParams(int $index = 0): array {
    $this->assertArrayHasKey($index, $this->renderedValues, 'No email was rendered');
    $message = WorkflowMessage::create(NewChecksumLinkMessage::WORKFLOW, [
      'modelProps' => $this->renderedValues[$index],
    ]);
    return $message->export('tplParams');
  }

  /**
   * The address they gave us is their primary one, so nothing has changed.
   */
  public function testPrimaryEmailIsSentTheLink(): void {
    $this->processMessageWithoutQueuing([
      'email' => $this->primaryEmail,
      'page' => 'EmailPreferences',
    ]);

    $values = $this->getTemplateParams();
    $this->assertTrue($values['accountFound']);
    $this->assertFalse($values['isSecondaryEmail']);
    $this->assertEquals('EmailPreferences', $values['targetPage']);
    $this->assertEquals($this->getContactID(), $values['contactID']);
    $this->assertStringContainsString('contact_id=' . $this->getContactID(), $values['url']);
    $this->assertNull($values['preferencesUrl']);

    $this->assertEquals(1, $this->getMailingCount());
    $this->assertEquals($this->primaryEmail, $this->getMailing(0)['to_address']);
  }

  /**
   * The link goes to the address they typed, flagged as not the one we hold.
   */
  public function testSecondaryEmailIsSentTheLinkAndFlaggedAsNotPrimary(): void {
    $this->createEmail($this->secondaryEmail, $this->getContactID(), FALSE);
    $this->createContribution(['contact_id' => $this->getContactID()]);

    $this->processMessageWithoutQueuing([
      'email' => $this->secondaryEmail,
      'page' => 'DonorPortal',
    ]);

    $values = $this->getTemplateParams();
    $this->assertTrue($values['accountFound']);
    $this->assertTrue($values['isSecondaryEmail']);
    $this->assertEquals('DonorPortal', $values['targetPage']);
    // The portal can't change an email, so they get a preference center link too.
    $this->assertStringContainsString('EmailPreferences', $values['preferencesUrl']);

    // Sent to the address they used, not the primary one we hold for them.
    $this->assertEquals($this->secondaryEmail, $this->getMailing(0)['to_address']);
  }

  /**
   * The preference center can change an email itself, so it needs no extra link.
   */
  public function testSecondaryEmailForPreferenceCentreHasNoExtraLink(): void {
    $this->createEmail($this->secondaryEmail, $this->getContactID(), FALSE);

    $this->processMessageWithoutQueuing([
      'email' => $this->secondaryEmail,
      'page' => 'EmailPreferences',
    ]);

    $values = $this->getTemplateParams();
    $this->assertTrue($values['isSecondaryEmail']);
    $this->assertNull($values['preferencesUrl']);
    $this->assertEquals($this->secondaryEmail, $this->getMailing(0)['to_address']);
  }

  /**
   * Two contacts share the address as a secondary, so we can't tell whose it is.
   */
  public function testSecondaryEmailOnMultipleContactsIsNotFound(): void {
    $otherContactID = $this->createIndividual(
      ['email_primary.email' => 'other-primary@example.com'], 'other_mouse'
    );
    $this->createEmail($this->secondaryEmail, $this->getContactID(), FALSE);
    $this->createEmail($this->secondaryEmail, $otherContactID, FALSE);

    $this->processMessageWithoutQueuing([
      'email' => $this->secondaryEmail,
      'page' => 'DonorPortal',
    ]);

    $this->assertAccountNotFound($this->secondaryEmail);
  }

  /**
   * An address we hold for nobody still gets an answer.
   */
  public function testUnknownEmailIsToldWeCannotFindAnAccount(): void {
    $this->processMessageWithoutQueuing([
      'email' => 'nobody@example.com',
      'page' => 'DonorPortal',
    ]);

    $this->assertAccountNotFound('nobody@example.com');
  }

  /**
   * There is nothing for the portal to show a contact who has never donated.
   */
  public function testDonorPortalForNonDonorIsNotFound(): void {
    $this->processMessageWithoutQueuing([
      'email' => $this->primaryEmail,
      'page' => 'DonorPortal',
    ]);

    $this->assertAccountNotFound($this->primaryEmail);
  }

  /**
   * A contact with no donor segment at all has never donated either.
   */
  public function testDonorPortalWithNoSegmentIsNotFound(): void {
    \CRM_Core_DAO::executeQuery('DELETE FROM wmf_donor WHERE entity_id = %1', [
      1 => [$this->getContactID(), 'Integer'],
    ]);

    $this->processMessageWithoutQueuing([
      'email' => $this->primaryEmail,
      'page' => 'DonorPortal',
    ]);

    $this->assertAccountNotFound($this->primaryEmail);
  }

  /**
   * The portal shows donations from every contact sharing the primary email, so
   * a donation on a duplicate contact still counts as having donated.
   */
  public function testDonorPortalCountsDonationsOnDuplicateContacts(): void {
    $duplicateContactID = $this->createIndividual(
      ['email_primary.email' => $this->primaryEmail], 'duplicate_mouse'
    );
    $this->createContribution(['contact_id' => $duplicateContactID]);

    $this->processMessageWithoutQueuing([
      'email' => $this->primaryEmail,
      'page' => 'DonorPortal',
    ]);

    $values = $this->getTemplateParams();
    $this->assertTrue($values['accountFound']);
    $this->assertEquals('DonorPortal', $values['targetPage']);
  }

  /**
   * A contact with no donations can still manage their email preferences.
   */
  public function testPreferenceCentreDoesNotNeedDonations(): void {
    $this->processMessageWithoutQueuing([
      'email' => $this->primaryEmail,
      'page' => 'EmailPreferences',
    ]);

    $this->assertTrue($this->getTemplateParams()['accountFound']);
  }

  /**
   * Sending a link is recorded against the contact, as before.
   */
  public function testActivityIsCreatedWhenLinkIsSent(): void {
    $this->processMessageWithoutQueuing([
      'email' => $this->primaryEmail,
      'page' => 'EmailPreferences',
    ]);

    $activities = $this->getEmailActivities($this->getContactID());
    $this->assertCount(1, $activities);
    $activity = reset($activities);
    $this->assertEquals('Requested page: EmailPreferences', $activity['details']);
    $this->assertEquals('new_checksum_link', $activity['Email.Workflow']);
  }

  /**
   * The activity says when we sent the secondary email version.
   */
  public function testActivityNotesSecondaryEmailVersion(): void {
    $this->createEmail($this->secondaryEmail, $this->getContactID(), FALSE);

    $this->processMessageWithoutQueuing([
      'email' => $this->secondaryEmail,
      'page' => 'EmailPreferences',
    ]);

    $activities = $this->getEmailActivities($this->getContactID());
    $this->assertCount(1, $activities);
    $this->assertEquals(
      'Requested page: EmailPreferences. Sent secondary email version.',
      reset($activities)['details']
    );
  }

  /**
   * A bad page is rejected before we go looking for anyone.
   */
  public function testInvalidPageThrows(): void {
    $this->expectExceptionMessage("Bad 'page' parameter Nonsense");
    $this->processMessageWithoutQueuing([
      'email' => 'nobody@example.com',
      'page' => 'Nonsense',
    ]);
  }

  /**
   * A message with neither a contact ID nor an email is rejected.
   */
  public function testMissingContactAndEmailThrows(): void {
    $this->expectExceptionMessage('Donor preferences link request message needs contact ID or email');
    $this->processMessageWithoutQueuing(['page' => 'DonorPortal']);
  }

  /**
   * A message with only a contact ID has no address to send the not found email to.
   */
  public function testContactIdForDonorPortalWithoutDonationsSendsNothing(): void {
    $this->processMessageWithoutQueuing([
      'contactID' => $this->getContactID(),
      'page' => 'DonorPortal',
    ]);

    $this->assertEquals(0, $this->getMailingCount());
  }

  /**
   * Assert we sent the not found email and pointed nobody at an account.
   *
   * @param string $toAddress
   */
  private function assertAccountNotFound(string $toAddress): void {
    $values = $this->getTemplateParams();
    $this->assertFalse($values['accountFound']);
    // Nothing that would log anyone in.
    $this->assertNull($values['url']);
    $this->assertNull($values['contactID']);
    $this->assertStringNotContainsString('checksum', $values['retryUrl']);

    $this->assertEquals(1, $this->getMailingCount());
    $this->assertEquals($toAddress, $this->getMailing(0)['to_address']);
    $this->assertCount(0, $this->getEmailActivities($this->getContactID()));
  }

  /**
   * @param int $contactID
   *
   * @return array
   */
  private function getEmailActivities(int $contactID): array {
    return (array) Activity::get(FALSE)
      ->addSelect('details', 'subject', 'Email.Workflow')
      ->addWhere('activity_type_id:name', '=', 'Email')
      ->addWhere('target_contact_id', 'CONTAINS', $contactID)
      ->execute();
  }

  /**
   * Create a secondary email for a contact.
   *
   * @param string $email
   * @param int $contactID
   * @param bool $isPrimary
   */
  private function createEmail(string $email, int $contactID, bool $isPrimary = TRUE): void {
    $this->createTestEntity('Email', [
      'email' => $email,
      'contact_id' => $contactID,
      'is_primary' => $isPrimary,
      'location_type_id' => \CRM_Core_BAO_LocationType::getDefault()->id,
    ], $email . $contactID);
  }

}

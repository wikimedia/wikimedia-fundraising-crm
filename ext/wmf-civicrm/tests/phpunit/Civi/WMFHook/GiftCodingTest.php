<?php

namespace Civi\WMFHook;

use Civi\Api4\Activity;
use Civi\Api4\Contact;
use Civi\Api4\Contribution;
use Civi\Api4\ContributionSoft;
use Civi\Api4\CustomField;
use Civi\Api4\Mailing;
use Civi\Api4\MailingProviderData;
use Civi\Test\EntityTrait;
use Civi\WMFEnvironmentTrait;
use Civi\WMFTaskQueueTrait;
use PHPUnit\Framework\TestCase;

/**
 * @group GiftCoding
 */
class GiftCodingTest extends TestCase {
  use WMFEnvironmentTrait;
  use WMFTaskQueueTrait;
  use EntityTrait;

  private const NOTIFICATION_QUEUE_NAME = 'email';

  protected int $donorID;

  public function setUp(): void {
    parent::setUp();
    $this->setUpWMFEnvironment();
    $this->resetQueue(self::NOTIFICATION_QUEUE_NAME);
    $this->donorID = $this->createIndividual();
    $this->setSetting('wmf_relationship_types_for_donation_attribution', ['Spouse of', 'Holds a Donor Advised Fund of']);
  }

  public function tearDown(): void {
    // Mailing & MailingProviderData aren't cleaned up by contact deletion.
    \CRM_Core_DAO::executeQuery("DELETE FROM civicrm_mailing_provider_data WHERE contact_identifier LIKE 'gift-coding-test-%'");
    \CRM_Core_DAO::executeQuery("DELETE FROM civicrm_mailing WHERE name LIKE 'GiftCoding Test Mailing%'");
    $this->tearDownWMFEnvironment();
    parent::tearDown();
  }

  /**
   * Run the notification queue and get the recipients of the sent emails.
   */
  protected function sendNotifications(): array {
    $runner = new \CRM_Queue_Runner([
      'queue' => \Civi::queue(self::NOTIFICATION_QUEUE_NAME, ['type' => 'Sql']),
      'errorMode' => \CRM_Queue_Runner::ERROR_ABORT,
    ]);
    $runner->runAll();
    return \CRM_Core_DAO::executeQuery('SELECT recipient_email, body FROM civicrm_mailing_spool ORDER BY id')->fetchAll();
  }

  protected function getContribution(int $contributionID): array {
    return Contribution::get(FALSE)
      ->addWhere('id', '=', $contributionID)
      ->addSelect(
        'Gift_Data.Appeal',
        'Gift_Data.Appeal:name',
        'Gift_Data.Package',
        'Appeal_Change_Reason.Change_Reason:name',
        'Appeal_Change_Reason.Entity_Table',
        'Appeal_Change_Reason.Entity_ID',
      )
      ->execute()->single();
  }

  protected function createSoftCredit(array $contribution, int $contactID, string $type = 'donor-advised_fund'): void {
    ContributionSoft::create(FALSE)->setValues([
      'contribution_id' => $contribution['id'],
      'contact_id' => $contactID,
      'soft_credit_type_id:name' => $type,
      'amount' => $contribution['total_amount'],
    ])->execute();
  }

  // ---------------------------------------------------------------------
  // MG / Direct Mail activity tests
  // ---------------------------------------------------------------------

  /**
   * Create a contribution with the Direct Mail channel by default.
   */
  protected function createDirectMailContribution(array $params = []): array {
    return $this->createContribution(array_merge([
      'contact_id' => $this->donorID,
      'Gift_Data.Channel' => 'Direct Mail',
    ], $params));
  }

  /**
   * Create an activity of the given type, targeting the donor.
   */
  protected function createActivity(string $type, array $params = []): int {
    return $this->createTestEntity('Activity', array_merge([
      'activity_type_id:name' => $type,
      'status_id:name' => $type === 'Major Gifts Engagement' ? 'Scheduled' : 'Completed',
      'target_contact_id' => [$this->donorID],
      'source_contact_id' => $this->donorID,
      'subject' => "Test $type",
    ], $params))['id'];
  }

  protected function getActivityStatus(int $activityID): string {
    return Activity::get(FALSE)
      ->addWhere('id', '=', $activityID)
      ->addSelect('status_id:name')
      ->execute()->single()['status_id:name'];
  }

  protected function setRelationshipManager(): void {
    Contact::update(FALSE)
      ->addValue('Prospect.Relationship_Manager', '1')
      ->addWhere('id', '=', $this->donorID)
      ->execute();
  }

  /**
   * A channel outside Direct Mail / Direct Mail Upload / Other Offline
   * is ignored, even when a matching activity exists.
   */
  public function testWrongChannelIsIgnored(): void {
    $activityID = $this->createActivity('Major Gifts Engagement');

    $this->createDirectMailContribution(['Gift_Data.Channel' => 'Other Online']);

    $this->assertEquals('Scheduled', $this->getActivityStatus($activityID));
  }

  /**
   * A Recurring Gift - Cash contribution is not changed, even when a matching
   * activity exists.
   */
  public function testRecurringGiftCashIsIgnored(): void {
    $activityID = $this->createActivity('Major Gifts Engagement');

    $contribution = $this->createDirectMailContribution(['financial_type_id:name' => 'Recurring Gift - Cash']);

    $this->assertEquals('Scheduled', $this->getActivityStatus($activityID));
    $this->assertEmpty($this->getContribution($contribution['id'])['Gift_Data.Appeal']);
  }

  /**
   * Editing an existing contribution is ignored.
   */
  public function testEditIsIgnored(): void {
    $contribution = $this->createContribution([
      'contact_id' => $this->donorID,
      'Gift_Data.Channel' => 'Other Online',
    ]);
    $activityID = $this->createActivity('Major Gifts Engagement');

    Contribution::update(FALSE)
      ->addValue('Gift_Data.Channel', 'Direct Mail')
      ->addWhere('id', '=', $contribution['id'])
      ->execute();

    $this->assertEquals('Scheduled', $this->getActivityStatus($activityID));
  }

  /**
   * A contribution saved via APIv3 (e.g. from the UI), which passes
   * custom fields as "custom_<id>" rather than dot-notation, still
   * gets checked.
   */
  public function testLegacyApiCustomFieldParamsAreChecked(): void {
    $activityID = $this->createActivity('Major Gifts Engagement', [
      'Major_Gifts_Engagement.Appeal:name' => 'facebook',
    ]);
    $channelFieldId = CustomField::get(FALSE)
      ->addWhere('custom_group_id:name', '=', 'Gift_Data')
      ->addWhere('name', '=', 'Channel')
      ->execute()->single()['id'];

    $contribution = civicrm_api3('Contribution', 'create', [
      'contact_id' => $this->donorID,
      'total_amount' => 1,
      'financial_type_id' => 'Donation',
      'custom_' . $channelFieldId => 'Direct Mail',
    ]);

    $this->assertEquals('Completed', $this->getActivityStatus($activityID));
    $this->assertEquals('facebook', $this->getContribution($contribution['id'])['Gift_Data.Appeal:name']);
  }

  /**
   * A Scheduled MG Engagement activity targeting the donor is completed,
   * and its Appeal value is copied onto the contribution.
   */
  public function testDonorMGEngagementActivityCompleted(): void {
    $activityID = $this->createActivity('Major Gifts Engagement', [
      'Major_Gifts_Engagement.Appeal:name' => 'facebook',
    ]);

    $contribution = $this->createDirectMailContribution();

    $this->assertEquals('Completed', $this->getActivityStatus($activityID));
    $updated = $this->getContribution($contribution['id']);
    $this->assertEquals('facebook', $updated['Gift_Data.Appeal:name']);
    $this->assertEquals('MG_Engagement', $updated['Appeal_Change_Reason.Change_Reason:name']);
    $this->assertEquals('civicrm_activity', $updated['Appeal_Change_Reason.Entity_Table']);
    $this->assertEquals($activityID, $updated['Appeal_Change_Reason.Entity_ID']);
  }

  /**
   * An MG Engagement activity without an Appeal sets the Appeal to MGGO plus
   * the two-digit year of the activity.
   */
  public function testMGEngagementActivityWithoutAppealSetsMGGOAppeal(): void {
    $activityID = $this->createActivity('Major Gifts Engagement', ['Major_Gifts_Engagement.Appeal' => '']);

    $contribution = $this->createDirectMailContribution();

    $updated = $this->getContribution($contribution['id']);
    $this->assertEquals('MGGO' . date('y'), $updated['Gift_Data.Appeal']);
    $this->assertEquals('MG_Engagement', $updated['Appeal_Change_Reason.Change_Reason:name']);
    $this->assertEquals($activityID, $updated['Appeal_Change_Reason.Entity_ID']);
  }

  /**
   * With no matching activity, a donor with a Relationship Manager gets MGGO
   * plus the two-digit year of the donation.
   */
  public function testRelationshipManagerSetsMGGOAppeal(): void {
    $this->setRelationshipManager();

    $contribution = $this->createDirectMailContribution(['receive_date' => '2025-03-01']);

    $updated = $this->getContribution($contribution['id']);
    $this->assertEquals('MGGO25', $updated['Gift_Data.Appeal']);
    $this->assertEquals('Relationship_Manager', $updated['Appeal_Change_Reason.Change_Reason:name']);
    $this->assertEquals('civicrm_contact', $updated['Appeal_Change_Reason.Entity_Table']);
    $this->assertEquals($this->donorID, $updated['Appeal_Change_Reason.Entity_ID']);
  }

  /**
   * The relationship manager MGGO appeal applies to any channel.
   */
  public function testRelationshipManagerSetsMGGOAppealForOnlineChannel(): void {
    $this->setRelationshipManager();

    $contribution = $this->createDirectMailContribution(['Gift_Data.Channel' => 'Other Online', 'receive_date' => '2025-03-01']);

    $this->assertEquals('MGGO25', $this->getContribution($contribution['id'])['Gift_Data.Appeal']);
  }

  /**
   * Matching Gift doesn't get the relationship manager MGGO appeal.
   */
  public function testRelationshipManagerExcludedGiftTypeIsIgnored(): void {
    $this->setRelationshipManager();

    $contribution = $this->createDirectMailContribution(['Gift_Data.Campaign' => 'Matching Gift']);

    $this->assertEmpty($this->getContribution($contribution['id'])['Gift_Data.Appeal']);
  }

  /**
   * A Direct Mail activity appeal is applied over the relationship manager
   * MGGO appeal.
   */
  public function testDirectMailActivityWinsOverRelationshipManager(): void {
    $this->setRelationshipManager();
    $this->createActivity('Direct Mail', ['direct_mail_data.direct_mail_appeal' => 'facebook']);

    $contribution = $this->createDirectMailContribution();

    $this->assertEquals('facebook', $this->getContribution($contribution['id'])['Gift_Data.Appeal']);
  }

  /**
   * An existing appeal on an online donation isn't overwritten by the
   * relationship manager.
   */
  public function testRelationshipManagerDoesNotOverwriteExistingAppeal(): void {
    $this->setRelationshipManager();

    $contribution = $this->createDirectMailContribution(['Gift_Data.Channel' => 'Other Online', 'Gift_Data.Appeal' => 'facebook']);

    $this->assertEquals('facebook', $this->getContribution($contribution['id'])['Gift_Data.Appeal']);
  }

  /**
   * A default replaceable appeal on an online donation gets the relationship
   * manager MGGO appeal.
   */
  public function testRelationshipManagerOverwritesSpontaneousAppeal(): void {
    $this->setRelationshipManager();

    $contribution = $this->createDirectMailContribution(['Gift_Data.Channel' => 'Other Online', 'Gift_Data.Appeal' => 'spontaneous', 'receive_date' => '2025-03-01']);

    $this->assertEquals('MGGO25', $this->getContribution($contribution['id'])['Gift_Data.Appeal']);
  }

  /**
   * An activity dated after the donation's receive date is ignored.
   */
  public function testActivityAfterReceiveDateIsIgnored(): void {
    $activityID = $this->createActivity('Major Gifts Engagement', [
      'Major_Gifts_Engagement.Appeal:name' => 'facebook',
    ]);

    $contribution = $this->createDirectMailContribution(['receive_date' => '-10 days']);

    $this->assertEquals('Scheduled', $this->getActivityStatus($activityID));
    $this->assertEmpty($this->getContribution($contribution['id'])['Gift_Data.Appeal']);
  }

  /**
   * The 90-day activity window counts back from the receive date.
   */
  public function testActivityWindowIsRelativeToReceiveDate(): void {
    $activityID = $this->createActivity('Major Gifts Engagement', [
      'activity_date_time' => '-100 days',
      'Major_Gifts_Engagement.Appeal:name' => 'facebook',
    ]);

    $contribution = $this->createDirectMailContribution(['receive_date' => '-20 days']);

    $this->assertEquals('Completed', $this->getActivityStatus($activityID));
    $this->assertEquals('facebook', $this->getContribution($contribution['id'])['Gift_Data.Appeal:name']);
  }

  public function directMailActivityTypeProvider(): array {
    return [
      'Direct Mail' => ['Direct Mail'],
      'Direct Mail Upload' => ['Direct Mail Upload'],
    ];
  }

  /**
   * A Completed Direct Mail / Direct Mail Upload activity targeting the
   * donor copies its Appeal and Package onto the contribution.
   *
   * @dataProvider directMailActivityTypeProvider
   */
  public function testDonorDirectMailActivityCopiesAppealAndPackage(string $activityType): void {
    $activityID = $this->createActivity($activityType, [
      'direct_mail_data.direct_mail_appeal' => 'facebook',
      'direct_mail_data.direct_mail_package' => 'OCT1B3ES',
    ]);

    $contribution = $this->createDirectMailContribution();

    $updated = $this->getContribution($contribution['id']);
    $this->assertEquals('facebook', $updated['Gift_Data.Appeal']);
    $this->assertEquals('OCT1B3ES', $updated['Gift_Data.Package']);
    $this->assertEquals('Direct_Mail', $updated['Appeal_Change_Reason.Change_Reason:name']);
    $this->assertEquals('civicrm_activity', $updated['Appeal_Change_Reason.Entity_Table']);
    $this->assertEquals($activityID, $updated['Appeal_Change_Reason.Entity_ID']);
  }

  /**
   * With no matching activity, mailing or relationship manager, the appeal
   * stays empty.
   */
  public function testNoMatchingActivityIsANoop(): void {
    $contribution = $this->createDirectMailContribution();

    $updated = $this->getContribution($contribution['id']);
    $this->assertEmpty($updated['Gift_Data.Appeal']);
    $this->assertEmpty($updated['Appeal_Change_Reason.Change_Reason:name']);
  }

  /**
   * An existing non-"White Mail" Appeal is left alone, but a matching MG
   * Engagement activity is still marked Completed.
   */
  public function testExistingAppealNotOverwrittenButActivityStillCompleted(): void {
    $activityID = $this->createActivity('Major Gifts Engagement', [
      'Major_Gifts_Engagement.Appeal:name' => 'facebook',
    ]);

    $contribution = $this->createDirectMailContribution(['Gift_Data.Appeal:name' => 'event']);

    $this->assertEquals('Completed', $this->getActivityStatus($activityID));
    $this->assertEquals('event', $this->getContribution($contribution['id'])['Gift_Data.Appeal:name']);
  }

  /**
   * An existing "White Mail" Appeal gets overwritten by the matching
   * activity's Appeal.
   */
  public function testWhiteMailAppealIsOverwritten(): void {
    $this->createActivity('Major Gifts Engagement', [
      'Major_Gifts_Engagement.Appeal:name' => 'facebook',
    ]);

    $contribution = $this->createDirectMailContribution(['Gift_Data.Appeal:name' => 'White Mail']);

    $this->assertEquals('facebook', $this->getContribution($contribution['id'])['Gift_Data.Appeal:name']);
  }

  /**
   * An appeal in the wmf_replaceable_appeals setting gets overwritten.
   */
  public function testAppealInReplaceableAppealsSettingIsOverwritten(): void {
    $this->setSetting('wmf_replaceable_appeals', ['event']);
    $this->createActivity('Major Gifts Engagement', [
      'Major_Gifts_Engagement.Appeal:name' => 'facebook',
    ]);

    $contribution = $this->createDirectMailContribution(['Gift_Data.Appeal:name' => 'event']);

    $this->assertEquals('facebook', $this->getContribution($contribution['id'])['Gift_Data.Appeal:name']);
  }

  /**
   * An Appeal already set is not overwritten by a matching Direct Mail activity
   * that has its own appeal/package.
   */
  public function testOtherOfflineExistingAppealNotOverwritten(): void {
    $this->createActivity('Direct Mail', [
      'direct_mail_data.direct_mail_appeal' => 'facebook',
      'direct_mail_data.direct_mail_package' => 'OCT1B3ES',
    ]);

    $contribution = $this->createDirectMailContribution(['Gift_Data.Appeal:name' => 'event']);

    $updated = $this->getContribution($contribution['id']);
    $this->assertEquals('event', $updated['Gift_Data.Appeal:name']);
    $this->assertEmpty($updated['Gift_Data.Package']);
  }

  /**
   * No match on the donor, but the individual holding the donor's DAF, related
   * via the DAF soft credit, has a matching activity.
   */
  public function testDAFSoftCreditRelatedContactFallback(): void {
    $softCreditContactID = $this->createIndividual();
    $activityID = $this->createActivity('Major Gifts Engagement', [
      'target_contact_id' => [$softCreditContactID],
      'source_contact_id' => $softCreditContactID,
      'Major_Gifts_Engagement.Appeal:name' => 'facebook',
    ]);

    $contribution = $this->createDirectMailContribution(['contact_id' => $this->createOrganization()]);
    $this->createSoftCredit($contribution, $softCreditContactID);

    $this->assertEquals('Completed', $this->getActivityStatus($activityID));
    $updated = $this->getContribution($contribution['id']);
    $this->assertEquals('facebook', $updated['Gift_Data.Appeal:name']);
    $this->assertEquals('MG_Engagement', $updated['Appeal_Change_Reason.Change_Reason:name']);
  }

  /**
   * A DAF soft credit contact's MG Engagement activity is completed even when the Appeal is already set.
   */
  public function testDAFSoftCreditRelatedContactActivityCompletedWithExistingAppeal(): void {
    $softCreditContactID = $this->createIndividual();
    $activityID = $this->createActivity('Major Gifts Engagement', [
      'target_contact_id' => [$softCreditContactID],
    ]);

    $contribution = $this->createDirectMailContribution([
      'contact_id' => $this->createOrganization(),
      'Gift_Data.Appeal:name' => 'event',
    ]);
    $this->createSoftCredit($contribution, $softCreditContactID);

    $this->assertEquals('Completed', $this->getActivityStatus($activityID));
    $this->assertEquals('event', $this->getContribution($contribution['id'])['Gift_Data.Appeal:name']);
  }

  /**
   * An Appeal set from the donor's MG Engagement activity isn't overridden by
   * an older activity of a contact related via the DAF soft credit.
   */
  public function testDAFSoftCreditRelatedContactOlderActivityDoesNotOverrideDonorActivity(): void {
    $donorID = $this->createOrganization();
    $softCreditContactID = $this->createIndividual();
    $activityID = $this->createActivity('Major Gifts Engagement', [
      'target_contact_id' => [$donorID],
      'activity_date_time' => '-1 day',
      'Major_Gifts_Engagement.Appeal:name' => 'facebook',
    ]);
    $this->createActivity('Direct Mail', [
      'target_contact_id' => [$softCreditContactID],
      'activity_date_time' => '-5 days',
      'direct_mail_data.direct_mail_appeal' => 'event',
    ]);

    $contribution = $this->createDirectMailContribution(['contact_id' => $donorID]);
    $this->createSoftCredit($contribution, $softCreditContactID);

    $updated = $this->getContribution($contribution['id']);
    $this->assertEquals('facebook', $updated['Gift_Data.Appeal:name']);
    $this->assertEquals($activityID, $updated['Appeal_Change_Reason.Entity_ID']);
  }

  /**
   * A newer activity of a contact related via the DAF soft credit overrides
   * an Appeal set from the donor's older activity.
   */
  public function testDAFSoftCreditRelatedContactNewerActivityOverridesDonorActivity(): void {
    $donorID = $this->createOrganization();
    $softCreditContactID = $this->createIndividual();
    $this->createActivity('Major Gifts Engagement', [
      'target_contact_id' => [$donorID],
      'activity_date_time' => '-5 days',
      'Major_Gifts_Engagement.Appeal:name' => 'facebook',
    ]);
    $activityID = $this->createActivity('Direct Mail', [
      'target_contact_id' => [$softCreditContactID],
      'activity_date_time' => '-1 day',
      'direct_mail_data.direct_mail_appeal' => 'event',
    ]);

    $contribution = $this->createDirectMailContribution(['contact_id' => $donorID]);
    $this->assertEquals('facebook', $this->getContribution($contribution['id'])['Gift_Data.Appeal:name']);
    $this->createSoftCredit($contribution, $softCreditContactID);

    $updated = $this->getContribution($contribution['id']);
    $this->assertEquals('event', $updated['Gift_Data.Appeal']);
    $this->assertEquals($activityID, $updated['Appeal_Change_Reason.Entity_ID']);
  }

  /**
   * An MG Engagement activity already completed by an earlier donation isn't
   * used when a DAF soft credit relates its contact.
   */
  public function testDAFSoftCreditRelatedContactPreviouslyCompletedActivityIsIgnored(): void {
    $donorID = $this->createOrganization();
    $softCreditContactID = $this->createIndividual();
    $activityID = $this->createActivity('Major Gifts Engagement', [
      'target_contact_id' => [$donorID],
      'activity_date_time' => '-5 days',
      'Major_Gifts_Engagement.Appeal:name' => 'facebook',
    ]);
    $this->createActivity('Major Gifts Engagement', [
      'target_contact_id' => [$softCreditContactID],
      'status_id:name' => 'Completed',
      'activity_date_time' => '-1 day',
      'Major_Gifts_Engagement.Appeal:name' => 'event',
    ]);

    $contribution = $this->createDirectMailContribution(['contact_id' => $donorID]);
    $this->createSoftCredit($contribution, $softCreditContactID);

    $updated = $this->getContribution($contribution['id']);
    $this->assertEquals('facebook', $updated['Gift_Data.Appeal:name']);
    $this->assertEquals($activityID, $updated['Appeal_Change_Reason.Entity_ID']);
  }

  /**
   * A soft credit of a type that creates no relationship is ignored.
   */
  public function testSoftCreditOfUnlistedTypeIsIgnored(): void {
    $softCreditContactID = $this->createIndividual();
    $activityID = $this->createActivity('Major Gifts Engagement', [
      'target_contact_id' => [$softCreditContactID],
      'source_contact_id' => $softCreditContactID,
    ]);

    $contribution = $this->createDirectMailContribution();
    $this->createSoftCredit($contribution, $softCreditContactID, 'in_memory_of');

    $this->assertEquals('Scheduled', $this->getActivityStatus($activityID));
  }

  /**
   * A related contact's newer activity wins over the donor's older one, and
   * both MG Engagement activities are completed.
   */
  public function testRelatedContactNewerActivityWinsOverDonorActivity(): void {
    $spouseID = $this->createIndividual();
    $this->createTestEntity('Relationship', [
      'contact_id_a' => $this->donorID,
      'contact_id_b' => $spouseID,
      'relationship_type_id:name' => 'Spouse of',
    ]);
    $donorActivityID = $this->createActivity('Major Gifts Engagement', [
      'activity_date_time' => '-5 days',
      'Major_Gifts_Engagement.Appeal:name' => 'facebook',
    ]);
    $spouseActivityID = $this->createActivity('Major Gifts Engagement', [
      'target_contact_id' => [$spouseID],
      'activity_date_time' => '-1 day',
      'Major_Gifts_Engagement.Appeal:name' => 'event',
    ]);

    $contribution = $this->createDirectMailContribution();

    $this->assertEquals('event', $this->getContribution($contribution['id'])['Gift_Data.Appeal:name']);
    $this->assertEquals('Completed', $this->getActivityStatus($donorActivityID));
    $this->assertEquals('Completed', $this->getActivityStatus($spouseActivityID));
  }

  /**
   * No match on the donor, but a contact related via a relationship type in
   * the setting (Spouse of) has a matching activity.
   */
  public function testRelatedContactFallback(): void {
    $spouseID = $this->createIndividual();
    $activityID = $this->createActivity('Major Gifts Engagement', [
      'target_contact_id' => [$spouseID],
      'source_contact_id' => $spouseID,
    ]);
    $this->createTestEntity('Relationship', [
      'contact_id_a' => $this->donorID,
      'contact_id_b' => $spouseID,
      'relationship_type_id:name' => 'Spouse of',
    ]);

    $this->createDirectMailContribution();

    $this->assertEquals('Completed', $this->getActivityStatus($activityID));
  }

  /**
   * A relationship of a type not in the setting is ignored.
   */
  public function testRelatedContactOfUnlistedTypeIsIgnored(): void {
    $employerID = $this->createOrganization();
    $activityID = $this->createActivity('Major Gifts Engagement', [
      'target_contact_id' => [$employerID],
      'source_contact_id' => $employerID,
    ]);
    $this->createTestEntity('Relationship', [
      'contact_id_a' => $this->donorID,
      'contact_id_b' => $employerID,
      'relationship_type_id:name' => 'Employee of',
    ]);

    $this->createDirectMailContribution();

    $this->assertEquals('Scheduled', $this->getActivityStatus($activityID));
  }

  /**
   * When more than one activity matches, the most recent one's
   * Appeal/Package wins.
   */
  public function testMostRecentActivityWinsForAppealAndPackage(): void {
    $this->createActivity('Direct Mail', [
      'activity_date_time' => '-5 days',
      'direct_mail_data.direct_mail_appeal' => 'facebook',
      'direct_mail_data.direct_mail_package' => 'OCT1B2ES',
    ]);
    $this->createActivity('Direct Mail', [
      'activity_date_time' => '-1 day',
      'direct_mail_data.direct_mail_appeal' => 'event',
      'direct_mail_data.direct_mail_package' => 'OCT1B3ES',
    ]);

    $contribution = $this->createDirectMailContribution();

    $updated = $this->getContribution($contribution['id']);
    $this->assertEquals('event', $updated['Gift_Data.Appeal']);
    $this->assertEquals('OCT1B3ES', $updated['Gift_Data.Package']);
  }

  /**
   * A matching MG Engagement activity is completed even when a Direct Mail
   * activity also matches in the same run.
   */
  public function testMGEngagementCompletedAlongsideDirectMailMatch(): void {
    $mgActivityID = $this->createActivity('Major Gifts Engagement', ['activity_date_time' => '-2 days']);
    $this->createActivity('Direct Mail', ['activity_date_time' => '-1 day']);

    $this->createDirectMailContribution();

    $this->assertEquals('Completed', $this->getActivityStatus($mgActivityID));
  }

  /**
   * A completed MG Engagement activity notifies the source contact's own
   * wikimedia.org primary email.
   */
  public function testMGEngagementNotificationSentToSourceContactPrimaryEmail(): void {
    $sourceContactID = $this->createIndividual(['email_primary.email' => 'staffer@wikimedia.org']);
    $this->createActivity('Major Gifts Engagement', ['source_contact_id' => $sourceContactID]);

    $this->createDirectMailContribution();

    $emails = $this->sendNotifications();
    $this->assertCount(1, $emails);
    $this->assertEquals('staffer@wikimedia.org', $emails[0]['recipient_email']);
    $this->assertStringContainsString('selectedChild=contribute', $emails[0]['body']);
  }

  /**
   * When the source contact's primary email isn't wikimedia.org, a
   * non-primary wikimedia.org email on the same contact is used instead.
   */
  public function testMGEngagementNotificationFallsBackToNonPrimaryWikimediaEmail(): void {
    $sourceContactID = $this->createIndividual(['email_primary.email' => 'staffer@personal.example.com']);
    $this->createTestEntity('Email', [
      'contact_id' => $sourceContactID,
      'email' => 'staffer@wikimedia.org',
      'is_primary' => FALSE,
    ]);
    $this->createActivity('Major Gifts Engagement', ['source_contact_id' => $sourceContactID]);

    $this->createDirectMailContribution();

    $this->assertEquals('staffer@wikimedia.org', $this->sendNotifications()[0]['recipient_email']);
  }

  /**
   * When the source contact has no wikimedia.org email at all, the
   * configured default notification email is used.
   */
  public function testMGEngagementNotificationFallsBackToDefaultSetting(): void {
    $this->setSetting('wmf_mg_fallback_notification_email', 'fallback@wikimedia.org');
    $sourceContactID = $this->createIndividual(['email_primary.email' => 'staffer@personal.example.com']);
    $this->createActivity('Major Gifts Engagement', ['source_contact_id' => $sourceContactID]);

    $this->createDirectMailContribution();

    $this->assertEquals('fallback@wikimedia.org', $this->sendNotifications()[0]['recipient_email']);
  }

  /**
   * A Direct Mail / Direct Mail Upload match never sends a notification -
   * only Major Gifts Engagement completion does.
   */
  public function testDirectMailMatchDoesNotSendNotification(): void {
    $this->createActivity('Direct Mail', [
      'direct_mail_data.direct_mail_appeal' => 'facebook',
    ]);

    $this->createDirectMailContribution();

    $this->assertCount(0, $this->sendNotifications());
  }

  // ---------------------------------------------------------------------
  // DAF mailing checks
  // ---------------------------------------------------------------------

  /**
   * Create a contribution with the Donor Advised Fund campaign.
   */
  protected function createDAFContribution(array $params = []): array {
    return $this->createContribution(array_merge([
      'contact_id' => $this->donorID,
      'Gift_Data.Campaign' => 'Donor Advised Fund',
    ], $params));
  }

  /**
   * Create a Mailing with the given Appeal, returning its hash (the value
   * MailingProviderData.mailing_identifier references).
   */
  protected function createDAFMailing(string $appeal = 'DAF Match', string $hash = 'sp12345678'): string {
    Mailing::create(FALSE)
      ->addValue('name', 'GiftCoding Test Mailing')
      ->addValue('hash', $hash)
      ->addValue('Mailing_Appeal.Appeal', $appeal)
      ->execute();
    return $hash;
  }

  /**
   * Record a MailingProviderData event for a contact against a mailing.
   */
  protected function recordMailingEvent(string $mailingHash, int $contactID, string $eventType = 'Sent', string $when = 'now'): void {
    MailingProviderData::create(FALSE)->setValues([
      'contact_id' => $contactID,
      'contact_identifier' => 'gift-coding-test-' . uniqid('', TRUE),
      'email' => 'test@example.com',
      'event_type' => $eventType,
      'mailing_identifier' => $mailingHash,
      'recipient_action_datetime' => date('Y-m-d H:i:s', strtotime($when)),
    ])->execute();
  }

  /**
   * A campaign other than Donor Advised Fund is ignored.
   */
  public function testCampaignNotDAFIsIgnored(): void {
    $mailingHash = $this->createDAFMailing();
    $this->recordMailingEvent($mailingHash, $this->donorID);

    $contribution = $this->createDAFContribution(['Gift_Data.Campaign' => 'Online Gift']);

    $this->assertEmpty($this->getContribution($contribution['id'])['Gift_Data.Appeal']);
  }

  /**
   * A DAF mailing exists, but with no recorded event for the donor, is a
   * no-op.
   */
  public function testNoMatchingMailingIsANoop(): void {
    $this->createDAFMailing();

    $contribution = $this->createDAFContribution();

    $this->assertEmpty($this->getContribution($contribution['id'])['Gift_Data.Appeal']);
  }

  /**
   * A Sent event on a mailing with a DAF appeal sets the contribution's
   * Appeal to that mailing's appeal.
   */
  public function testDAFMailingSetsAppeal(): void {
    $mailingHash = $this->createDAFMailing('DAF Match');
    $this->recordMailingEvent($mailingHash, $this->donorID);

    $contribution = $this->createDAFContribution();

    $updated = $this->getContribution($contribution['id']);
    $this->assertEquals('DAF Match', $updated['Gift_Data.Appeal']);
    $this->assertEquals('DAF_Email', $updated['Appeal_Change_Reason.Change_Reason:name']);
    $this->assertEquals('civicrm_mailing', $updated['Appeal_Change_Reason.Entity_Table']);
    $this->assertEquals(
      Mailing::get(FALSE)->addWhere('hash', '=', $mailingHash)->execute()->single()['id'],
      $updated['Appeal_Change_Reason.Entity_ID']
    );
  }

  /**
   * An existing non-"White Mail" Appeal is not overwritten by a DAF mailing.
   */
  public function testExistingAppealNotOverwrittenByDAFMailing(): void {
    $mailingHash = $this->createDAFMailing('DAF Match');
    $this->recordMailingEvent($mailingHash, $this->donorID);

    $contribution = $this->createDAFContribution(['Gift_Data.Appeal:name' => 'event']);

    $updated = $this->getContribution($contribution['id']);
    $this->assertEquals('event', $updated['Gift_Data.Appeal:name']);
    $this->assertEmpty($updated['Appeal_Change_Reason.Change_Reason:name']);
  }

  /**
   * An existing "White Mail" Appeal gets overwritten by the matching DAF
   * mailing's appeal.
   */
  public function testWhiteMailAppealIsOverwrittenByDAFMailing(): void {
    $mailingHash = $this->createDAFMailing('DAF Match');
    $this->recordMailingEvent($mailingHash, $this->donorID, 'Open');

    $contribution = $this->createDAFContribution(['Gift_Data.Appeal:name' => 'White Mail']);

    $this->assertEquals('DAF Match', $this->getContribution($contribution['id'])['Gift_Data.Appeal']);
  }

  /**
   * A mailing whose appeal doesn't contain "DAF" is ignored, even with a
   * matching event.
   */
  public function testMailingWithoutDAFAppealIsIgnored(): void {
    $mailingHash = $this->createDAFMailing('Facebook');
    $this->recordMailingEvent($mailingHash, $this->donorID);

    $contribution = $this->createDAFContribution();

    $this->assertEmpty($this->getContribution($contribution['id'])['Gift_Data.Appeal']);
  }

  /**
   * A mailing event older than 90 days is ignored.
   */
  public function testOldMailingEventOutsideWindowIsIgnored(): void {
    $mailingHash = $this->createDAFMailing('DAF Match');
    $this->recordMailingEvent($mailingHash, $this->donorID, 'Sent', '-91 days');

    $contribution = $this->createDAFContribution();

    $this->assertEmpty($this->getContribution($contribution['id'])['Gift_Data.Appeal']);
  }

  /**
   * A mailing event after the donation's receive date is ignored.
   */
  public function testMailingEventAfterReceiveDateIsIgnored(): void {
    $this->recordMailingEvent($this->createDAFMailing(), $this->donorID);

    $contribution = $this->createDAFContribution(['receive_date' => '-10 days']);

    $this->assertEmpty($this->getContribution($contribution['id'])['Gift_Data.Appeal']);
  }

  /**
   * When more than one DAF mailing event matches, the most recent one's
   * appeal wins.
   */
  public function testMostRecentDAFMailingWins(): void {
    $olderMailingHash = $this->createDAFMailing('DAF Old', 'sp12345678');
    $this->recordMailingEvent($olderMailingHash, $this->donorID, 'Sent', '-5 days');
    $newerMailingHash = $this->createDAFMailing('DAF New', 'sp87654321');
    $this->recordMailingEvent($newerMailingHash, $this->donorID, 'Click Through', '-1 day');

    $contribution = $this->createDAFContribution();

    $this->assertEquals('DAF New', $this->getContribution($contribution['id'])['Gift_Data.Appeal']);
  }

  /**
   * A DAF mailing to the individual related to the donor's DAF sets the Appeal.
   */
  public function testDAFSoftCreditRelatedContactMailingSetsAppeal(): void {
    $softCreditContactID = $this->createIndividual();
    $this->recordMailingEvent($this->createDAFMailing('DAF Match'), $softCreditContactID);

    $contribution = $this->createDAFContribution(['contact_id' => $this->createOrganization()]);
    $this->createSoftCredit($contribution, $softCreditContactID);

    $updated = $this->getContribution($contribution['id']);
    $this->assertEquals('DAF Match', $updated['Gift_Data.Appeal']);
    $this->assertEquals('DAF_Email', $updated['Appeal_Change_Reason.Change_Reason:name']);
  }

  /**
   * A newer DAF mailing to a contact related via the DAF soft credit
   * overrides an Appeal set from the donor's older mailing.
   */
  public function testDAFSoftCreditRelatedContactNewerMailingOverridesDonorMailing(): void {
    $donorID = $this->createOrganization();
    $softCreditContactID = $this->createIndividual();
    $this->recordMailingEvent($this->createDAFMailing('DAF Donor', 'sp12345678'), $donorID, 'Sent', '-5 days');
    $this->recordMailingEvent($this->createDAFMailing('DAF Soft', 'sp87654321'), $softCreditContactID, 'Sent', '-1 day');

    $contribution = $this->createDAFContribution(['contact_id' => $donorID]);
    $this->assertEquals('DAF Donor', $this->getContribution($contribution['id'])['Gift_Data.Appeal']);
    $this->createSoftCredit($contribution, $softCreditContactID);

    $this->assertEquals('DAF Soft', $this->getContribution($contribution['id'])['Gift_Data.Appeal']);
  }

  /**
   * A DAF mailing to a contact related via the DAF soft credit doesn't
   * overwrite an Appeal that wasn't set by gift coding.
   */
  public function testDAFSoftCreditRelatedContactMailingDoesNotOverwriteOtherAppeal(): void {
    $softCreditContactID = $this->createIndividual();
    $this->recordMailingEvent($this->createDAFMailing('DAF Match'), $softCreditContactID);

    $contribution = $this->createDAFContribution(['contact_id' => $this->createOrganization(), 'Gift_Data.Appeal:name' => 'event']);
    $this->createSoftCredit($contribution, $softCreditContactID);

    $this->assertEquals('event', $this->getContribution($contribution['id'])['Gift_Data.Appeal:name']);
  }

}

<?php

namespace Civi\Api4\WMFContact;

use Civi\Api4\WMFContact;
use Civi\Test\EntityTrait;
use Civi\WMFEnvironmentTrait;
use PHPUnit\Framework\TestCase;

/**
 * @group epcV4
 **/
class GetDonorSummaryTest extends TestCase {
  use WMFEnvironmentTrait;
  use EntityTrait;

  public function setUp(): void {
    $this->setUpWMFEnvironment();
    parent::setUp();
    \CRM_Core_Config::singleton()->userPermissionClass = new \CRM_Core_Permission_UnitTests();
    \CRM_Core_Config::singleton()->userPermissionClass->permissions = ['access CiviContribute'];
  }

  /**
   * When the donor has an active recurring, the inactive list should only
   * include one recurring and only if it has a cancel date in the last 60 days.
   */
  public function testRecentlyCancelledRecurringFilter(): void {
    $contactID = $this->createIndividual(['email_primary.email' => 'donor-portal@example.org']);

    $this->createRecurring('active', $contactID, 'In Progress', NULL, date('Y-m-d H:i:s', strtotime('-1 year')));
    $this->createRecurring('oldCancelled', $contactID, 'Cancelled', date('Y-m-d H:i:s', strtotime('-200 days')));

    $this->assertEquals([$this->ids['ContributionRecur']['active']], $this->getDonorSummaryRecurrings($contactID));

    $this->createRecurring('recentCancelled', $contactID, 'Cancelled', date('Y-m-d H:i:s', strtotime('-10 days')));

    $returnedIDs = $this->getDonorSummaryRecurrings($contactID);
    $this->assertContains($this->ids['ContributionRecur']['active'], $returnedIDs);
    $this->assertContains($this->ids['ContributionRecur']['recentCancelled'], $returnedIDs);
    $this->assertNotContains($this->ids['ContributionRecur']['oldCancelled'], $returnedIDs);
  }

  /**
   * A recurring cancelled before the donor's current active recurring started
   * should not be shown, even if it was cancelled within the last 60 days.
   */
  public function testCancelledBeforeActiveStartExcluded(): void {
    $contactID = $this->createIndividual(['email_primary.email' => 'donor-portal@example.org']);

    $this->createRecurring('active', $contactID, 'In Progress', NULL, date('Y-m-d H:i:s', strtotime('-20 days')));
    $this->createRecurring('cancelledBeforeStart', $contactID, 'Cancelled', date('Y-m-d H:i:s', strtotime('-40 days')));

    $this->assertEquals([$this->ids['ContributionRecur']['active']], $this->getDonorSummaryRecurrings($contactID));

    $this->createRecurring('cancelledAfterStart', $contactID, 'Cancelled', date('Y-m-d H:i:s', strtotime('-10 days')));

    $returnedIDs = $this->getDonorSummaryRecurrings($contactID);
    $this->assertContains($this->ids['ContributionRecur']['active'], $returnedIDs);
    $this->assertContains($this->ids['ContributionRecur']['cancelledAfterStart'], $returnedIDs);
    $this->assertNotContains($this->ids['ContributionRecur']['cancelledBeforeStart'], $returnedIDs);
  }

  /**
   * With no active recurrings, the most recently modified (and only the most recently)
   * cancelled recurring is shown even when every cancellation is older than 60 days.
   */
  public function testNoActiveReturnsSingleOldCancelled(): void {
    $contactID = $this->createIndividual(['email_primary.email' => 'donor-portal@example.org']);

    $this->createRecurring('olderCancelled', $contactID, 'Cancelled', date('Y-m-d H:i:s', strtotime('-300 days')), NULL, date('Y-m-d H:i:s', strtotime('-300 days')));
    $this->createRecurring('oldCancelled', $contactID, 'Cancelled', date('Y-m-d H:i:s', strtotime('-200 days')), NULL, date('Y-m-d H:i:s', strtotime('-200 days')));

    $this->assertEquals([$this->ids['ContributionRecur']['oldCancelled']], $this->getDonorSummaryRecurrings($contactID));
  }

  protected function createRecurring(string $identifier, int $contactID, string $status, ?string $cancelDate = NULL, ?string $startDate = NULL, ?string $modifiedDate = NULL): void {
    $this->createTestEntity('ContributionRecur', array_filter([
      'contact_id' => $contactID,
      'payment_processor_id:name' => 'adyen',
      'amount' => 5,
      'frequency_unit' => 'month',
      'contribution_status_id:name' => $status,
      'cancel_date' => $cancelDate,
      'start_date' => $startDate,
      'modified_date' => $modifiedDate ?? 'now',
    ]), $identifier);
  }

  protected function getDonorSummaryRecurrings(int $contactID): array {
    $recurrings = WMFContact::getDonorSummary(FALSE)
      ->setContact_id($contactID)
      ->setChecksum(\CRM_Contact_BAO_Contact_Utils::generateChecksum($contactID))
      ->execute()->first()['recurringContributions'] ?? [];
    return array_column($recurrings, 'id');
  }

}

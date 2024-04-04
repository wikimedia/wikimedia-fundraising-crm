<?php

use Civi\Api4\ContributionTracking;
use SmashPig\Core\DataStores\QueueWrapper;

/**
 * @group WmfAudit
 * @group CiviAudit
 */
class WmfAuditTest extends \Civi\WMFAudit\BaseAuditTestCase {

  public function testGetContributionTrackingData(): void {
    $expectedContributionTrackingData = [
      'utm_source' => 'testBanner.default~default~default~default~control.cc',
      'utm_medium' => 'test_medium',
      'utm_campaign' => 'test_campaign',
      'language' => 'en',
      // It is called tracking_date in the database but is normalized to date for the rest of the audit code
      // The audit also expects a Unix timestamp
      'date' => 1684211880,
      'utm_payment_method' => 'cc'
    ];

    $all_data = [
      'contribution_tracking_id' => $this->createContributionTracking()
    ];
    $contribution_tracking_data = wmf_audit_get_contribution_tracking_data($all_data);
    $this->assertEquals($expectedContributionTrackingData, $contribution_tracking_data);

    // Now check that nothing weird happens when it doesn't exist.
    ContributionTracking::delete(FALSE)->addWhere('id', '=', $all_data['contribution_tracking_id'])->execute();
  }

  public function testFillEmptyContributionTrackingData(): void {
    $id = $this->getMaxContributionTrackingId() + 1;
    $auditData = [
      'contribution_tracking_id' => $id,
      'date' => 1699557307,
      'payment_method' => 'cc'
    ];
    $contributionTrackingData = wmf_audit_get_contribution_tracking_data($auditData);
    $this->assertEquals('audit..cc', $contributionTrackingData['utm_source']);
    $this->assertEquals($auditData['date'], $contributionTrackingData['date']);
    $queueMessage = QueueWrapper::getQueue('contribution-tracking')->pop();
    $this->assertEquals($id, $queueMessage['id']);
    $expectedTs = (new DateTime('@' . $auditData['date'], new DateTimeZone('UTC')))
      ->format('YmdHis');
    $this->assertEquals($expectedTs, $queueMessage['ts']);
  }

  private function createContributionTracking() {
    $id = $this->getMaxContributionTrackingId();
    $contribution_tracking_id = $id + 1;

    $data = [
      'id' => $contribution_tracking_id,
      'utm_source' => 'testBanner.default~default~default~default~control.cc',
      'utm_medium' => 'test_medium',
      'utm_campaign' => 'test_campaign',
      'language' => 'en',
      'tracking_date' => '2023-05-16 04:38:00'
    ];

    ContributionTracking::save(FALSE)
      ->addRecord($data)
      ->execute();

    $this->ids['ContributionTracking'][] = $contribution_tracking_id;
    return $contribution_tracking_id;
  }

  protected function getMaxContributionTrackingId() {
      return ContributionTracking::get(FALSE)
        ->setSelect(['id'])
        ->addOrderBy('id', 'DESC')
        ->setLimit(1)
        ->execute()->first()['id'] ?? 0;
  }
}

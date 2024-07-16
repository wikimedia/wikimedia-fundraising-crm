<?php

use Civi\Api4\ContributionTracking;
use SmashPig\Core\DataStores\QueueWrapper;

/**
 * @group WmfAudit
 * @group CiviAudit
 */
class WmfAuditTest extends \Civi\WMFAudit\BaseAuditTestCase {

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

  protected function getMaxContributionTrackingId() {
      return ContributionTracking::get(FALSE)
        ->setSelect(['id'])
        ->addOrderBy('id', 'DESC')
        ->setLimit(1)
        ->execute()->first()['id'] ?? 0;
  }
}

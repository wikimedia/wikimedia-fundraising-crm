<?php

use Civi\Api4\ContributionTracking;

/**
 * @group WmfAudit
 * @group CiviAudit
 */
class WmfAuditTest extends BaseAuditTestCase {

  public function testGetContributionTrackingData() {
    $expectedContributionTrackingData = [
      'utm_source' => 'testBanner.default~default~default~default~control.cc',
      'utm_medium' => 'testmedium',
      'utm_campaign' => 'testcampaign',
      'language' => 'en',
      // It is called tracking_date in the database but is normalized to date for the rest of the audit code
      // The audit also expects a Unix timestamp
      'date' => 1684211880,
      'utm_payment_method' => 'cc'
    ];

    $all_data['contribution_tracking_id'] = $this->createContributionTracking();
    $contribution_tracking_data = wmf_audit_get_contribution_tracking_data($all_data);
    $this->assertEquals($expectedContributionTrackingData,$contribution_tracking_data);
  }

  private function createContributionTracking() {
    $id = ContributionTracking::get(FALSE)->execute()->last()['id'];

    $data = [
      'id' => $id+1,
      'utm_source' => 'testBanner.default~default~default~default~control.cc',
      'utm_medium' => 'testmedium',
      'utm_campaign' => 'testcampaign',
      'language' => 'en',
      'tracking_date' => '2023-05-16 04:38:00'
    ];

    ContributionTracking::save(FALSE)
      ->addRecord($data)
      ->execute();

    $contribution_tracking_id = ContributionTracking::get(FALSE)->execute()->last()['id'];
    $this->ids['ContributionTracking'][] = $contribution_tracking_id;
    return $contribution_tracking_id;
  }
}

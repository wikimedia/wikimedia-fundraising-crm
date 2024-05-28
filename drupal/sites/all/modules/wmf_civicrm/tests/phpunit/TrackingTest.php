<?php

use Civi\Api4\Contribution;
use Civi\WMFQueue\ContributionTrackingQueueConsumer;

class TrackingTest extends BaseWmfDrupalPhpUnitTestCase {

  public function testGetContributionTracking() {
    $contribution = $this->createTestEntity('Contribution', [
      'financial_type_id:name' => 'Donation',
      'total_amount' => 60,
      'receive_date' => 'now',
      'contact_id' => $this->createIndividual(),
    ]);
    $id = $this->getContributionTracking([
      'contribution_id' => $contribution['id'],
    ]);
    $returnedTracking = wmf_civicrm_get_contribution_tracking([
      'contribution_tracking_id' => $id,
    ]);
    $this->assertEquals($id, $returnedTracking['id']);
    $this->ids['ContributionTracking'][$id] = $id;
  }

  protected function getContributionTracking($params = []): int {
    $tracking = [
      'utm_source' => '..rpp',
      'utm_medium' => 'civicrm',
      'ts' => '',
    ];
    $id = wmf_civicrm_insert_contribution_tracking(array_merge($tracking, $params));
    $this->processContributionTrackingQueue();
    return $id;
  }

}

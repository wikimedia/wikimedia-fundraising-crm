<?php
use Civi\Api4\Contribution;
use queue2civicrm\contribution_tracking\ContributionTrackingQueueConsumer;

class TrackingTest extends BaseWmfDrupalPhpUnitTestCase
{
  use \Civi\Test\ContactTestTrait;
  public function testGetContributionTracking() {
    $contribution = $this->getContribution();
    $id = $this->getContributionTracking([
      'contribution_id' => $contribution['id']
    ]);
    $returnedTracking = wmf_civicrm_get_contribution_tracking([
      'contribution_tracking_id' => $id
    ]);
    $this->assertEquals($id, $returnedTracking['id']);
    $this->ids['ContributionTracking'][$id] = $id;
    $this->ids['Contribution'][$returnedTracking['contribution_id']] = $returnedTracking['contribution_id'];
  }


  protected function getContributionTracking($params = []): int {
    $tracking = array(
      'utm_source' => '..rpp',
      'utm_medium' => 'civicrm',
      'ts' => '',
    );
    $id = wmf_civicrm_insert_contribution_tracking(array_merge($tracking, $params));
    $this->consumeCtQueue();
    return $id;
  }

  protected function consumeCtQueue() {
    $consumer = new ContributionTrackingQueueConsumer('contribution-tracking');
    $consumer->dequeueMessages();
  }

  protected function getContribution($recurParams = []): array {
    $contactID = $recurParams['contact_id'] ?? $this->individualCreate();
    return Contribution::create(FALSE)->setValues(array_merge([
      'financial_type_id:name' => 'Donation',
      'total_amount' => 60,
      'receive_date' => 'now',
      'contact_id' => $contactID
    ], $recurParams))->execute()->first();
  }
}
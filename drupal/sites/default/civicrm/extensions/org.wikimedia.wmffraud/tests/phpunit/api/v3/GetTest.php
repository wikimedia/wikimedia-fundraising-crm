<?php

use Civi\Api4\ContributionTracking;
use Civi\WMFHelper\ContributionTracking as WMFHelper;
use SmashPig\Core\SequenceGenerators\Factory;
use Civi\WMFQueue\AntifraudQueueConsumer;

class GetTest extends BaseWmfDrupalPhpUnitTestCase {

  use \Civi\Test\ContactTestTrait;

  public function testGetRequest() {
    $contactID = $this->individualCreate();
    $this->createContributionEntriesWithFredge(['contact_id' => $contactID, 'order_id' => 'your-order']);
    $this->createContributionEntriesWithFredge(['contact_id' => $contactID, 'order_id' => 'my-order']);

    $fredges = $this->callAPISuccess('Fredge', 'get', ['contact_id' => $contactID]);
    $this->assertEquals(2, $fredges['count']);
  }

  private function createContributionEntriesWithFredge($params = []) {
    $contribution = \Civi\Api4\Contribution::create(FALSE)
      ->setValues(array_merge([
        'financial_type_id:name' => 'Donation',
        'receive_date' => 'now',
        'total_amount' => 55,
        'is_pay_later' => FALSE,
        'is_template' => FALSE,
      ], $params))
      ->execute()
      ->first();

    $generator = Factory::getSequenceGenerator('contribution-tracking');
    $contribution_tracking_id = $generator->getNext();
    ContributionTracking::save(FALSE)->addRecord(WMFHelper::getContributionTrackingParameters([
      'utm_source' => '..rpp',
      'utm_medium' => 'civicrm',
      'ts' => '',
      'contribution_id' => $contribution['id'],
      'id' => $contribution_tracking_id,
    ]))->execute()->first();

    $this->createFredgeTestRecord([
      'contribution_tracking_id' => $contribution_tracking_id,
      'validation_action' => 'accept',
      'user_ip' => '192.168.1.1',
      'date' => 'now',
      'order_id' => $params['order_id'],
    ]);
  }

  /**
   * @param array $params
   * Values for the fredge payments_fraud table
   */
  public function createFredgeTestRecord($params) {
    if (isset($params['date'])) {
      // Mangle back to unix time. Test more readable if calling function can use a date string.
      $params['date'] = strtotime($params['date']);
    }
    $params = array_merge([
      'gateway' => 'test',
      'payment_method' => 'tooth-fairy',
      'risk_score' => 10,
      'server' => 'daemon',
      'date' => strtotime('now'),
      'score_breakdown' => [],
    ], $params);

    wmf_common_create_smashpig_context('fraud_test');
    $consumer = new AntifraudQueueConsumer('payments-antifraud');
    $consumer->processMessageWithErrorHandling($params);
  }
}

<?php

use Civi\Test\ContactTestTrait;
use Civi\WMFQueue\AntifraudQueueConsumer;
use Civi\Test\HeadlessInterface;
use Civi\Test\HookInterface;
use Civi\Test\TransactionalInterface;

require_once __DIR__ . '/BaseTestClass.php';

class GetTest extends api_v3_BaseTestClass implements HeadlessInterface, HookInterface, TransactionalInterface {

  use ContactTestTrait;

  public function testGetRequest() {
    $contactID = $this->individualCreate();
    $this->createContributionEntriesWithFredge(['contact_id' => $contactID, 'order_id' => 'your-order']);
    $this->createContributionEntriesWithFredge(['contact_id' => $contactID, 'order_id' => 'my-order']);

    $fredges = $this->callAPISuccess('Fredge', 'get', ['contact_id' => $contactID]);
    $this->assertEquals(2, $fredges['count']);
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

    \CRM_SmashPig_ContextWrapper::createContext('fraud_test');
    $consumer = new AntifraudQueueConsumer('payments-antifraud');
    $consumer->processMessageWithErrorHandling($params);
  }

}

<?php

use Civi\Test\Api3TestTrait;
use Civi\Test\EntityTrait;
use Civi\Test\HeadlessInterface;
use Civi\Test\HookInterface;
use Civi\Test\TransactionalInterface;
use Civi\WMFEnvironmentTrait;
use Civi\WMFQueue\AntifraudQueueConsumer;
use PHPUnit\Framework\TestCase;

/**
 * FIXME - Add test description.
 *
 * Tips:
 *  - With HookInterface, you may implement CiviCRM hooks directly in the test class.
 *    Simply create corresponding functions (e.g. "hook_civicrm_post(...)" or similar).
 *  - With TransactionalInterface, any data changes made by setUp() or test****() functions will
 *    rollback automatically -- as long as you don't manipulate schema or truncate tables.
 *    If this test needs to manipulate schema or truncate tables, then either:
 *       a. Do all that using setupHeadless() and Civi\Test.
 *       b. Disable TransactionalInterface, and handle all setup/teardown yourself.
 *
 * @group headless
 */
class api_v3_BaseTestClass extends TestCase implements HeadlessInterface, HookInterface, TransactionalInterface {

  use Api3TestTrait;
  use EntityTrait;
  use WMFEnvironmentTrait;

  public function setUpHeadless() {
    // Civi\Test has many helpers, like install(), uninstall(), sql(), and sqlFile().
    // See: https://github.com/civicrm/org.civicrm.testapalooza/blob/master/civi-test.md
    return \Civi\Test::headless()
      ->installMe(__DIR__)
      ->apply();
  }

  /**
   * @param array $params
   *  Values for the fredge payments_fraud table
   */
  public function createFredgeTestRecord(array $params) {
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

  /**
   * @param array $contributionParams
   */
  protected function createContributionEntriesWithFredge($contributionParams) {
    $ids = $this->createContributionEntriesWithTracking($contributionParams);
    $this->createFredgeTestRecord([
      'contribution_tracking_id' => $ids['tracking_id'],
      'validation_action' => 'accept',
      'user_ip' => '192.168.1.1',
      'date' => '2017-05-20',
      'order_id' => $ids['order_id'],
    ]);
  }

  /**
   * @param $contributionParams
   *
   * @return array
   */
  protected function createContributionEntriesWithTracking($contributionParams) {
    $contribution = $this->createContributionEntries($contributionParams);
    $this->createContributionTracking(['contribution_id' => $contribution['id']], 'tracking');
    $orderID = isset($contributionParams['order_id']) ? $contributionParams['order_id'] : uniqid();
    return ['tracking_id' => $this->ids['ContributionTracking']['tracking'], 'order_id' => $orderID];
  }

  /**
   * @param $contributionParams
   *
   * @return array|int
   */
  protected function createContributionEntries($contributionParams) {
    $contribution = $this->callAPISuccess('Contribution', 'create', array_merge([
      'financial_type_id' => 'Donation',
      'receive_date' => '2017-05-20',
      'total_amount' => 55,
    ], $contributionParams));
    return $contribution;
  }

}

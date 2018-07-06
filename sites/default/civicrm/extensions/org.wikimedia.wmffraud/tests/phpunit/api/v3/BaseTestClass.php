<?php

use CRM_Wmffraud_ExtensionUtil as E;
use Civi\Test\HeadlessInterface;
use Civi\Test\HookInterface;
use Civi\Test\TransactionalInterface;
use queue2civicrm\fredge\AntifraudQueueConsumer;

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
class api_v3_BaseTestClass extends \PHPUnit_Framework_TestCase implements HeadlessInterface, HookInterface, TransactionalInterface {

  use \Civi\Test\Api3TestTrait;

  protected $createdValues = [];
  /**
   * The setup() method is executed before the test is executed (optional).
   */
  public function setUp() {
    parent::setUp();
    civicrm_initialize();
    CRM_Forgetme_Hook::testSetup();
  }

  public function setUpHeadless() {
    // Civi\Test has many helpers, like install(), uninstall(), sql(), and sqlFile().
    // See: https://github.com/civicrm/org.civicrm.testapalooza/blob/master/civi-test.md
    return \Civi\Test::headless()
      ->installMe(__DIR__)
      ->apply();
  }

  public function tearDown() {
    parent::tearDown();
  }

  /**
   * @param array $params
   *  Values for the fredge payments_fraud table
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
    // Ideally we wouldn't call a function in a module from an extension - but this extension is    very much a WMF special.
    $tracking = wmf_civicrm_insert_contribution_tracking(['contribution_id' => $contribution['id']]);
    $this->createdValues['contribution_tracking'] = $tracking;

    $orderID = isset($contributionParams['order_id']) ? $contributionParams['order_id'] : uniqid();
    return ['tracking_id' => $tracking, 'order_id' => $orderID];
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

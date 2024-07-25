<?php

namespace Civi\Api4;

use Civi\Test;
use Civi\Test\CiviEnvBuilder;
use Civi\Test\HeadlessInterface;
use Civi\WMFEnvironmentTrait;
use PHPUnit\Framework\TestCase;

/**
 * Test our api entities.
 *
 * @group headless
 */
class ActionTest extends TestCase implements HeadlessInterface {

  use Test\EntityTrait;
  use WMFEnvironmentTrait;

  /**
   * @throws \CRM_Core_Exception
   */
  public function tearDown(): void {
    PaymentsFraud::delete(FALSE)
      ->addWhere('gateway', '=', '123-pay-me')->execute();
    PaymentsInitial::delete(FALSE)
      ->addWhere('gateway', '=', '123-pay-me')->execute();
    PaymentsFraudBreakdown::delete(FALSE)
      ->addWhere('filter_name', '=', '123-pay-me')->execute();
  }

  /**
   * @throws \CRM_Extension_Exception_ParseException
   */
  public function setUpHeadless(): CiviEnvBuilder {
    return Test::headless()
      ->installMe(__DIR__)
      ->apply();
  }

  /**
   * Make sure our api actions work.
   *
   * @throws \CRM_Core_Exception
   */
  public function testApiActions(): void {
    $this->createContributionTracking([], 'fraud');
    $this->createTestEntity('PaymentsFraud', [
      'gateway' => '123-pay-me',
      'order_id' => 123,
      'validation_action' => 'fraud',
      'user_ip' => '1.1.1.1',
      'payment_method' => 'cc',
      'risk_score' => 4,
      'server' => 'staging',
      'date' => time(),
      'contribution_tracking_id' => $this->ids['ContributionTracking']['fraud'],
    ], 'test');
    $this->assertCount(1, PaymentsFraud::get(FALSE)
      ->addWhere('gateway', '=', '123-pay-me')
      ->execute());
    $this->createTestEntity('PaymentsFraudBreakdown', [
      'filter_name' => '123-pay-me',
      'payments_fraud_id' => $this->ids['PaymentsFraud']['test'],
      'risk_score' => 5,
    ]);
    $this->assertCount(1, PaymentsFraudBreakdown::get(FALSE)
      ->addWhere('filter_name', '=', '123-pay-me')
      ->execute());

    $this->createTestEntity('PaymentsInitial', [
      'gateway' => '123-pay-me',
      'order_id' => 123,
      'validation_action' => 'fraud',
      'server' => 'staging',
      'date' => time(),
      'contribution_tracking_id' => $this->ids['ContributionTracking']['fraud'],
      'payments_final_status' => 'done',
      'country' => 'US',
      'amount' => 5,
      'currency_code' => 'USD',
    ]);

    $this->assertCount(1, PaymentsInitial::get(FALSE)
      ->addWhere('gateway', '=', '123-pay-me')
      ->execute());
  }

}

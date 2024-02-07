<?php

namespace Civi\Api4;

use Civi\Test;
use Civi\Test\CiviEnvBuilder;
use Civi\Test\HeadlessInterface;
use PHPUnit\Framework\TestCase;

/**
 * Test our api entities.
 *
 * @group headless
 */
class ActionTest extends TestCase implements HeadlessInterface {

  use Test\EntityTrait;

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
    $this->createTestEntity('PaymentsFraud', [
      'gateway' => '123-pay-me',
    ], 'test');
    $this->assertCount(1, PaymentsFraud::get(FALSE)
      ->addWhere('gateway', '=', '123-pay-me')
      ->execute());
    $this->createTestEntity('PaymentsFraudBreakdown', [
      'filter_name' => '123-pay-me',
      'payments_fraud_id' => $this->ids['PaymentsFraud']['test'],
    ]);
    $this->assertCount(1, PaymentsFraudBreakdown::get(FALSE)
      ->addWhere('filter_name', '=', '123-pay-me')
      ->execute());

    $this->createTestEntity('PaymentsInitial', [
      'gateway' => '123-pay-me',
    ]);

    $this->assertCount(1, PaymentsInitial::get(FALSE)
      ->addWhere('gateway', '=', '123-pay-me')
      ->execute());
  }

}

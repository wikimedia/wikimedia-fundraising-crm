<?php

namespace Civi\Api4\Action\WMFAudit;

use Civi\Api4\Contribution;
use Civi\Api4\WMFAudit;
use Civi\Test\EntityTrait;
use Civi\WMFEnvironmentTrait;
use PHPUnit\Framework\TestCase;

/**
 * WMF Audit settlement test.
 */
class SettleTest extends TestCase {
  use WMFEnvironmentTrait;
  use EntityTrait;

  /**
   * Test we are declaring the fields for the api.
   *
   * @return void
   * @throws \CRM_Core_Exception
   */
  public function testGetFields(): void {
    $fields = WMFAudit::getFields(FALSE)
      ->setCheckPermissions(FALSE)
      ->setAction('settle')->execute()->indexBy('name');
    $this->assertEquals(TRUE, $fields['settled_total_amount']['required']);
  }

  public function testSettle(): void {
    $this->createTestEntity('Contact', ['contact_type' => 'individual', 'first_name' => 'daffy'], 'daffy');
    $this->createTestEntity('Contribution', [
      'receive_date' => '2025-07-17 15:54:32',
      'contact_id' => $this->ids['Contact']['daffy'],
      'financial_type_id:name' => 'Cash',
      'payment_instrument_id:name' => 'Credit Card',
      'total_amount' => 32,
      'contribution_extra.gateway' => 'adyen',
      'contribution_extra.gateway_txn_id' => 12345,
      'contribution_extra.original_currency' => 'NZD',
      'contribution_extra.original_amount' => 45,
    ], 'daffy');
    WMFAudit::settle(FALSE)
      ->setValues([
        'settled_currency' => 'USD',
        'settled_date' => '2025-07-17 17:23:23',
        'settled_total_amount' => 33.5,
        'contribution_id' => $this->ids['Contribution']['daffy'],
        'gateway' => 'adyen',
        'gateway_txn_id' => 12345,
        'settled_fee_amount' => '-.3',
      ])
      ->execute();
    $settledContribution = Contribution::get(FALSE)
      ->addWhere('contribution_extra.gateway_txn_id', '=', 12345)
      ->addWhere('contribution_extra.gateway', '=', 'adyen')
      ->execute()->single();
    $this->assertEquals(.3, $settledContribution['fee_amount']);
    $this->assertEquals(33.5, $settledContribution['total_amount']);
    $this->assertEquals(33.2, $settledContribution['net_amount']);
  }

}

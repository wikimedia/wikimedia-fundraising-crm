<?php

namespace Civi\Api4\Action\WMFAudit;

use Civi\Api4\Contribution;
use Civi\Api4\ContributionSoft;
use Civi\Api4\LineItem;
use Civi\Test\EntityTrait;
use Civi\WMFEnvironmentTrait;
use PHPUnit\Framework\TestCase;

/**
 * Batch Spec test.
 *
 * Tests the batch spec provider offers up batch related filters.
 */
class FinanceBatchSpecTest extends TestCase {

  use WMFEnvironmentTrait;
  use EntityTrait;

  public function testGetOptions(): void {
    $this->createTestEntity('Batch', ['mode_id:name' => 'Automatic Batch', 'title' => 'my_batch', 'name' => 'mine', 'status_id:name' => 'total_verified']);
    $fields = Contribution::getFields(FALSE)
      ->addWhere('name', 'IN', ['contribution_status_id', 'finance_batch'])
      ->setLoadOptions(TRUE)
      ->setAction('get')
      ->execute()->indexBy('name');
    $this->assertArrayHasKey('finance_batch', $fields);
    $this->assertNotEmpty($fields['finance_batch']['options']);
  }

  /**
   * Test filtering by the pseudo field finance_batch.
   *
   * This filter also affects the value of the settled_total_amount, settled_net_amount
   * and settled_fee_amount pseudofields.
   *
   * @return void
   * @throws \CRM_Core_Exception
   */
  public function testFilterByFinanceBatch() {
    $this->createTestEntity('Contribution', [
      'financial_type_id:name' => 'Cash',
      'trxn_id' => 'positive',
      'total_amount' => 10,
      'settled_total_amount' => 10,
      'contribution_status_id:name' => 'Refunded',
      'contact_id' => $this->createIndividual(),
      'contribution_settlement.settlement_batch_reference' => 'abc',
      'contribution_settlement.settled_donation_amount' => 10,
      'contribution_settlement.settled_fee_amount' => -.5,
      'contribution_settlement.settled_currency' => 'USD',
    ], 'positive');

    $this->createTestEntity('Contribution', [
      'financial_type_id:name' => 'Cash',
      'trxn_id' => 'negative',
      'total_amount' => 15,
      'contact_id' => $this->createIndividual(),
      'contribution_settlement.settlement_batch_reversal_reference' => 'abc',
      'contribution_settlement.settled_reversal_amount' => -15,
      'contribution_settlement.settled_fee_reversal_amount' => .5,
      'contribution_settlement.settlement_batch_reference' => 'xyz',
      'contribution_settlement.settled_donation_amount' => 15,
      'contribution_settlement.settled_fee_amount' => -.5,
      'contribution_settlement.settled_currency' => 'USD',
    ], 'negative');

    $contribution = Contribution::get(FALSE)
      ->addWhere('finance_batch', 'LIKE', 'abc')
      ->addSelect('settled_net_amount', 'settled_fee_amount', 'settled_total_amount', 'trxn_id')
      ->execute()->indexBy('trxn_id');
    $this->assertEquals(10, $contribution['positive']['settled_total_amount']);
    $this->assertEquals(-.5, $contribution['positive']['settled_fee_amount']);
    $this->assertEquals(9.5, $contribution['positive']['settled_net_amount']);
    $this->assertEquals(-15, $contribution['negative']['settled_total_amount']);
    $this->assertEquals(.5, $contribution['negative']['settled_fee_amount']);
    $this->assertEquals(-14.5, $contribution['negative']['settled_net_amount']);

    $this->createTestEntity('ContributionSoft', [
      'contribution_id' => $contribution['negative']['id'],
      'amount' => 15,
      'contact_id' => $this->createIndividual(),
    ]);

    $soft = ContributionSoft::get(FALSE)->addWhere('contribution_id.finance_batch', 'IN', ['abc'])
      ->addSelect('contribution_id.settled_net_amount')->execute()->first();
    $this->assertEquals(-14.5, $soft['contribution_id.settled_net_amount']);
  }

}

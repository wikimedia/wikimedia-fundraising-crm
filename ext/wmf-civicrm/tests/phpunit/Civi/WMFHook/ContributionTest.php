<?php

namespace Civi\WMFHook;

use Civi\Api4\Contribution;
use Civi\Test\EntityTrait;
use Civi\Test\HeadlessInterface;
use Civi\WMFEnvironmentTrait;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the derived contribution_extra values set in Contribution::pre.
 *
 * Uses APIv3 because apiPrepare already derives original currency & amount
 * for APIv4 creates.
 *
 * @group headless
 */
class ContributionTest extends TestCase implements HeadlessInterface {

  use WMFEnvironmentTrait;
  use EntityTrait;

  protected function createApi3Contribution(array $params): int {
    $contributionID = civicrm_api3('Contribution', 'create', array_merge([
      'contact_id' => $this->createIndividual(),
      'financial_type_id' => 'Donation',
      'total_amount' => 23,
    ], $params))['id'];
    $this->ids['Contribution'][] = $contributionID;
    return $contributionID;
  }

  protected function getExtra(int $contributionID): array {
    return Contribution::get(FALSE)
      ->addSelect('contribution_extra.gateway', 'contribution_extra.gateway_txn_id', 'contribution_extra.original_currency', 'contribution_extra.original_amount')
      ->addWhere('id', '=', $contributionID)
      ->execute()->single();
  }

  /**
   * Gateway & gateway txn id are derived from trxn_id.
   */
  public function testTrxnIDSetsGatewayFields(): void {
    $extra = $this->getExtra($this->createApi3Contribution(['trxn_id' => 'ADYEN ABC123']));

    $this->assertEquals('adyen', $extra['contribution_extra.gateway']);
    $this->assertEquals('ABC123', $extra['contribution_extra.gateway_txn_id']);
  }

  /**
   * Gateway & gateway txn id are derived from trxn_id on APIv4 creates.
   */
  public function testApiv4TrxnIDSetsGatewayFields(): void {
    $contributionID = $this->createTestEntity('Contribution', [
      'contact_id' => $this->createIndividual(),
      'financial_type_id:name' => 'Donation',
      'total_amount' => 23,
      'trxn_id' => 'ADYEN ABC123',
    ])['id'];
    $extra = $this->getExtra($contributionID);

    $this->assertEquals('adyen', $extra['contribution_extra.gateway']);
    $this->assertEquals('ABC123', $extra['contribution_extra.gateway_txn_id']);
  }

  /**
   * Original currency & amount are derived from source.
   */
  public function testSourceSetsOriginalCurrencyAndAmount(): void {
    $extra = $this->getExtra($this->createApi3Contribution(['source' => 'EUR 20.5']));

    $this->assertEquals('EUR', $extra['contribution_extra.original_currency']);
    $this->assertEquals(20.5, $extra['contribution_extra.original_amount']);
  }

  /**
   * A source with an unknown currency falls back to USD and the total amount.
   */
  public function testSourceWithInvalidCurrencyUsesUSD(): void {
    $extra = $this->getExtra($this->createApi3Contribution(['source' => 'XYZ 20.5']));

    $this->assertEquals('USD', $extra['contribution_extra.original_currency']);
    $this->assertEquals(23, $extra['contribution_extra.original_amount']);
  }

  /**
   * Explicitly passed values are not overwritten by derived ones.
   */
  public function testExplicitValuesNotOverwritten(): void {
    $extra = $this->getExtra($this->createApi3Contribution([
      'trxn_id' => 'ADYEN ABC123',
      'source' => 'EUR 20.5',
      $this->getCustomFieldKey('gateway') => 'gravy',
      $this->getCustomFieldKey('gateway_txn_id') => 'XYZ789',
      $this->getCustomFieldKey('original_currency') => 'CAD',
      $this->getCustomFieldKey('original_amount') => 30,
    ]));

    $this->assertEquals('gravy', $extra['contribution_extra.gateway']);
    $this->assertEquals('XYZ789', $extra['contribution_extra.gateway_txn_id']);
    $this->assertEquals('CAD', $extra['contribution_extra.original_currency']);
    $this->assertEquals(30, $extra['contribution_extra.original_amount']);
  }

  /**
   * Transaction Fees contributions get no derived values.
   */
  public function testTransactionFeesSkipped(): void {
    $extra = $this->getExtra($this->createApi3Contribution([
      'trxn_id' => 'Transaction Fees ABC123',
      'source' => 'EUR 20.5',
    ]));

    $this->assertEmpty($extra['contribution_extra.gateway']);
    $this->assertEmpty($extra['contribution_extra.original_currency']);
  }

  private function getCustomFieldKey(string $name): string {
    return 'custom_' . \CRM_Core_BAO_CustomField::getCustomFieldID($name, 'contribution_extra');
  }

}

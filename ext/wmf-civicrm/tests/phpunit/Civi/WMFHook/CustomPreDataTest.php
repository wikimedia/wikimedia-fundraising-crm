<?php

namespace Civi\WMFHook;

use Civi\Api4\Batch;
use Civi\Api4\Contribution;
use Civi\Api4\GatewayAccount;
use Civi\Api4\RelationshipCache;
use Civi\Test\HeadlessInterface;
use Civi\WMFEnvironmentTrait;
use PHPUnit\Framework\TestCase;
use Civi\Test\EntityTrait;

class CustomPreDataTest extends TestCase implements HeadlessInterface {

  use WMFEnvironmentTrait;
  use EntityTrait;

  /**
   * Tests the hook addition of donor advised fund relationship.
   *
   * The hook ensures that editing the donor advised fund custom field
   * leads to the relationship being created, allowing the field
   * to be added to batch data entry and imports.
   *
   * @return void
   * @throws \CRM_Core_Exception
   */
  public function testDonorAdvisedFundHook(): void {
    $this->createOrganization();
    $this->createIndividual();
    $this->createTestEntity('Contribution', [
      'contact_id' => $this->ids['Contact']['danger_mouse'],
      'total_amount' => 50,
      'donor_advised_fund.owns_donor_advised_for' => $this->ids['Contact']['organization'],
      'financial_type_id:name' => 'Donation',
    ]);
    $relationships = RelationshipCache::get(FALSE)
      ->addWhere('near_contact_id', '=', $this->ids['Contact']['danger_mouse'])
      ->execute();
    $this->assertCount(1, $relationships);

    // Now check what happens if the relationship already exists (hint should be nothing).
    $this->createTestEntity('Contribution', [
      'contact_id' => $this->ids['Contact']['danger_mouse'],
      'total_amount' => 50,
      'donor_advised_fund.owns_donor_advised_for' => $this->ids['Contact']['organization'],
      'financial_type_id:name' => 'Donation',
    ]);
    $relationships = RelationshipCache::get(FALSE)
      ->addWhere('near_contact_id', '=', $this->ids['Contact']['danger_mouse'])
      ->execute();
    $this->assertCount(1, $relationships);
  }

  /**
   * Editing an existing contribution to set the donor advised fund custom
   * field, without resubmitting contact_id, should still find the right
   * contact - contributionPre() only has contact_id directly available
   * when it's part of the same save, so createDonorAdvisedRelationshipFromCustomField()
   * needs to fall back to looking it up via the contribution id.
   *
   * @throws \CRM_Core_Exception
   */
  public function testDonorAdvisedFundHookOnEditWithoutContactId(): void {
    $this->createOrganization();
    $this->createIndividual();
    $contribution = $this->createTestEntity('Contribution', [
      'contact_id' => $this->ids['Contact']['danger_mouse'],
      'total_amount' => 50,
      'financial_type_id:name' => 'Donation',
    ]);

    // Edit without contact_id - the fallback lookup by entityID must kick in.
    Contribution::update(FALSE)
      ->addWhere('id', '=', $contribution['id'])
      ->addValue('donor_advised_fund.owns_donor_advised_for', $this->ids['Contact']['organization'])
      ->execute();

    $relationships = RelationshipCache::get(FALSE)
      ->addWhere('near_contact_id', '=', $this->ids['Contact']['danger_mouse'])
      ->execute();
    $this->assertCount(1, $relationships);
  }

  /**
   * Setting batch_data.settlement_gateway_account_id should populate the
   * legacy batch_data.settlement_gateway string field with the matching
   * GatewayAccount's name.
   *
   * @throws \CRM_Core_Exception
   */
  public function testSettlementGatewaySyncFromAccountId(): void {
    $adyenID = $this->getGatewayAccountId('adyen');
    $batch = $this->createTestBatch('sync_from_account_id_' . mt_rand(), [
      'batch_data.settlement_gateway_account_id' => $adyenID,
    ]);
    $saved = Batch::get(FALSE)
      ->addWhere('id', '=', $batch['id'])
      ->addSelect('batch_data.settlement_gateway', 'batch_data.settlement_gateway_account_id')
      ->execute()->single();
    $this->assertEquals('adyen', $saved['batch_data.settlement_gateway']);
    $this->assertEquals($adyenID, $saved['batch_data.settlement_gateway_account_id']);
  }

  /**
   * Setting batch_data.settlement_gateway (the legacy string field) should
   * populate batch_data.settlement_gateway_account_id with the matching
   * GatewayAccount's id.
   *
   * @throws \CRM_Core_Exception
   */
  public function testSettlementGatewaySyncFromGatewayName(): void {
    $dlocalID = $this->getGatewayAccountId('dlocal');
    $batch = $this->createTestBatch('sync_from_gateway_name_' . mt_rand(), [
      'batch_data.settlement_gateway' => 'dlocal',
    ]);
    $saved = Batch::get(FALSE)
      ->addWhere('id', '=', $batch['id'])
      ->addSelect('batch_data.settlement_gateway', 'batch_data.settlement_gateway_account_id')
      ->execute()->single();
    $this->assertEquals('dlocal', $saved['batch_data.settlement_gateway']);
    $this->assertEquals($dlocalID, $saved['batch_data.settlement_gateway_account_id']);
  }

  /**
   * If both fields are submitted together with conflicting values, the
   * id field should win - it's the more precise reference.
   *
   * @throws \CRM_Core_Exception
   */
  public function testSettlementGatewaySyncIdWinsOnConflict(): void {
    $adyenID = $this->getGatewayAccountId('adyen');
    $batch = $this->createTestBatch('sync_conflict_' . mt_rand(), [
      'batch_data.settlement_gateway_account_id' => $adyenID,
      'batch_data.settlement_gateway' => 'dlocal',
    ]);
    $saved = Batch::get(FALSE)
      ->addWhere('id', '=', $batch['id'])
      ->addSelect('batch_data.settlement_gateway', 'batch_data.settlement_gateway_account_id')
      ->execute()->single();
    $this->assertEquals('adyen', $saved['batch_data.settlement_gateway']);
    $this->assertEquals($adyenID, $saved['batch_data.settlement_gateway_account_id']);
  }

  /**
   * If the submitted settlement_gateway name doesn't match any
   * GatewayAccount, the sync should be a no-op rather than failing the
   * save or clearing the submitted value.
   *
   * @throws \CRM_Core_Exception
   */
  public function testSettlementGatewaySyncNoMatchLeavesFieldsAlone(): void {
    $batch = $this->createTestBatch('sync_no_match_' . mt_rand(), [
      'batch_data.settlement_gateway' => 'no_such_gateway_account',
    ]);
    $saved = Batch::get(FALSE)
      ->addWhere('id', '=', $batch['id'])
      ->addSelect('batch_data.settlement_gateway', 'batch_data.settlement_gateway_account_id')
      ->execute()->single();
    $this->assertEquals('no_such_gateway_account', $saved['batch_data.settlement_gateway']);
    $this->assertEmpty($saved['batch_data.settlement_gateway_account_id']);
  }

  /**
   * Look up the id of a real, managed GatewayAccount by name.
   *
   * @throws \CRM_Core_Exception
   */
  private function getGatewayAccountId(string $name): int {
    return (int) GatewayAccount::get(FALSE)
      ->addWhere('name', '=', $name)
      ->addSelect('id')
      ->execute()->single()['id'];
  }

  /**
   * Create a minimal total_verified Automatic Batch with the given
   * batch_data.* values merged in.
   *
   * @throws \CRM_Core_Exception
   */
  private function createTestBatch(string $name, array $batchDataValues): array {
    return $this->createTestEntity('Batch', $batchDataValues + [
      'name' => $name,
      'mode_id:name' => 'Automatic Batch',
      'status_id:name' => 'total_verified',
      'item_count' => 0,
    ], $name);
  }

}

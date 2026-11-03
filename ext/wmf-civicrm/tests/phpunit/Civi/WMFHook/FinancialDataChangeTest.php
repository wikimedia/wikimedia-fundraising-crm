<?php

namespace Civi\WMFHook;

use Civi\Api4\Contribution;
use Civi\Api4\CustomValue;
use Civi\Test\EntityTrait;
use Civi\Test\HeadlessInterface;
use Civi\WMFBatch\FinanceConstants;
use Civi\WMFEnvironmentTrait;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the FinancialDataChange::pre hook, which records a
 * FinancialDataAdjustment when a contribution's gl_code or financial_type_id
 * is corrected after it has already been settled into a batch.
 *
 * @group headless
 */
class FinancialDataChangeTest extends TestCase implements HeadlessInterface {

  use WMFEnvironmentTrait;
  use EntityTrait;

  public function tearDown(): void {
    if (!empty($this->ids['Batch'])) {
      \Civi\Api4\Batch::delete(FALSE)
        ->addWhere('id', 'IN', array_values($this->ids['Batch']))
        ->execute();
    }
    parent::tearDown();
  }

  /**
   * A gl_code change on a contribution already settled into an Exported
   * batch records a 'pending' adjustment, carrying the batch reference
   * forward as original_batch.
   */
  public function testGlCodeChangeOnExportedBatchCreatesPendingAdjustment(): void {
    $batchName = $this->createExportedBatch();
    $contributionID = $this->createSettledContribution($batchName, [
      'Gift_Data.gl_code' => 'GL43481',
    ]);

    Contribution::update(FALSE)
      ->addWhere('id', '=', $contributionID)
      ->addValue('Gift_Data.gl_code', 'GL43485')
      ->execute();

    $adjustment = $this->getAdjustment($contributionID);
    $this->assertEquals('pending', $adjustment['status']);
    $this->assertEquals($batchName, $adjustment['original_batch']);
    $this->assertEquals('GL43481', $adjustment['gl_code_before']);
    $this->assertEquals('GL43485', $adjustment['gl_code_after']);
    $this->assertFalse((bool) $adjustment['is_endowment_before']);
    $this->assertFalse((bool) $adjustment['is_endowment_after']);
  }

  /**
   * A financial_type_id change crossing into Endowment Gift, on a
   * contribution already settled into an Exported batch, records a
   * 'pending' adjustment even though gl_code didn't change.
   */
  public function testFinancialTypeChangeToEndowmentOnExportedBatchCreatesPendingAdjustment(): void {
    $batchName = $this->createExportedBatch();
    $contributionID = $this->createSettledContribution($batchName, [
      'financial_type_id:name' => 'Donation',
      'Gift_Data.gl_code' => 'GL43481',
    ]);

    Contribution::update(FALSE)
      ->addWhere('id', '=', $contributionID)
      ->addValue('financial_type_id:name', 'Endowment Gift')
      ->execute();

    $adjustment = $this->getAdjustment($contributionID);
    $this->assertEquals('pending', $adjustment['status']);
    $this->assertEquals($batchName, $adjustment['original_batch']);
    $this->assertEquals('GL43481', $adjustment['gl_code_before']);
    $this->assertEquals('GL43481', $adjustment['gl_code_after']);
    $this->assertFalse((bool) $adjustment['is_endowment_before']);
    $this->assertTrue((bool) $adjustment['is_endowment_after']);
  }

  /**
   * A financial_type_id change between two non-Endowment types, with no
   * gl_code change, doesn't need a cross-entity journal and so creates no
   * adjustment.
   */
  public function testNonEndowmentFinancialTypeChangeWithoutGlCodeChangeCreatesNoAdjustment(): void {
    $batchName = $this->createExportedBatch();
    $contributionID = $this->createSettledContribution($batchName, [
      'financial_type_id:name' => 'Donation',
      'Gift_Data.gl_code' => 'GL43481',
    ]);

    Contribution::update(FALSE)
      ->addWhere('id', '=', $contributionID)
      ->addValue('financial_type_id:name', 'Stock')
      ->execute();

    $this->assertNull($this->getAdjustment($contributionID));
  }

  /**
   * A gl_code change on a contribution that was never settled into a batch
   * has no journal to reverse, so creates no adjustment.
   */
  public function testChangeWithoutSettlementBatchReferenceCreatesNoAdjustment(): void {
    $contributionID = $this->createSettledContribution(NULL, [
      'Gift_Data.gl_code' => 'GL43481',
    ]);

    Contribution::update(FALSE)
      ->addWhere('id', '=', $contributionID)
      ->addValue('Gift_Data.gl_code', 'GL43485')
      ->execute();

    $this->assertNull($this->getAdjustment($contributionID));
  }

  /**
   * If the referenced batch hasn't been exported yet, there is no journal
   * to reverse - the not-yet-exported batch will just pick up the
   * corrected value when it is generated, so no adjustment is created.
   */
  public function testChangeWhenBatchNotYetExportedCreatesNoAdjustment(): void {
    $batchName = $this->createBatch('Open');
    $contributionID = $this->createSettledContribution($batchName, [
      'Gift_Data.gl_code' => 'GL43481',
    ]);

    Contribution::update(FALSE)
      ->addWhere('id', '=', $contributionID)
      ->addValue('Gift_Data.gl_code', 'GL43485')
      ->execute();

    $this->assertNull($this->getAdjustment($contributionID));
  }

  /**
   * A batch that is Partially Exported is a straddling, dangerous state -
   * the adjustment is recorded as 'needs_attention' (for manual review)
   * rather than 'pending', and original_batch is left unset so automatic
   * processing doesn't pick it up.
   */
  public function testPartiallyExportedBatchCreatesNeedsAttentionAdjustment(): void {
    $batchName = $this->createBatch('Partially Exported');
    $contributionID = $this->createSettledContribution($batchName, [
      'Gift_Data.gl_code' => 'GL43481',
    ]);

    Contribution::update(FALSE)
      ->addWhere('id', '=', $contributionID)
      ->addValue('Gift_Data.gl_code', 'GL43485')
      ->execute();

    $adjustment = $this->getAdjustment($contributionID);
    $this->assertEquals('needs_attention', $adjustment['status']);
    $this->assertEmpty($adjustment['original_batch']);
  }

  /**
   * When another process holds the settlement batch lock (e.g. GenerateBatch
   * is mid-export), the correction is recorded as 'needs_attention' rather
   * than risking a stale read of the batch's export status.
   */
  public function testLockNotAcquiredCreatesNeedsAttentionAdjustment(): void {
    $batchName = $this->createExportedBatch();
    $contributionID = $this->createSettledContribution($batchName, [
      'Gift_Data.gl_code' => 'GL43481',
    ]);

    $competingLock = $this->acquireCompetingLock(FinanceConstants::BATCH_LOCK_PREFIX . $batchName);

    Contribution::update(FALSE)
      ->addWhere('id', '=', $contributionID)
      ->addValue('Gift_Data.gl_code', 'GL43485')
      ->execute();

    $adjustment = $this->getAdjustment($contributionID);
    $this->assertEquals('needs_attention', $adjustment['status']);
    $this->assertEmpty($adjustment['original_batch']);

    // Release the competing connection's lock now that the assertions
    // above are done with it.
    $competingLock->disconnect();
  }

  private function createExportedBatch(string $identifier = 'default'): string {
    return $this->createBatch('Exported', $identifier);
  }

  private function createBatch(string $status, string $identifier = 'default'): string {
    $batchName = 'adyen_' . $identifier . '_' . 'USD';
    $this->createTestEntity('Batch', [
      'name' => $batchName,
      'mode_id:name' => 'Automatic Batch',
      'status_id:name' => $status,
      'item_count' => 1,
    ], $identifier);
    return $batchName;
  }

  private function createSettledContribution(?string $batchName, array $values): int {
    return $this->createTestEntity('Contribution', array_merge([
      'contact_id' => $this->createIndividual(),
      'financial_type_id:name' => 'Donation',
      'total_amount' => 10,
      'contribution_settlement.settlement_batch_reference' => $batchName,
    ], $values))['id'];
  }

  private function getAdjustment(int $contributionID): ?array {
    $adjustments = CustomValue::get('FinancialDataAdjustment', FALSE)
      ->addWhere('entity_id', '=', $contributionID)
      ->execute();
    $this->assertLessThanOrEqual(1, count($adjustments), 'Expected at most one adjustment row for this contribution');
    return $adjustments->first();
  }

}

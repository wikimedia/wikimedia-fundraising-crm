<?php

namespace Civi\WMFAudit;

use Civi\Api4\Batch;
use Civi\Api4\CustomValue;
use Civi\Api4\Action\WMFAudit\ReserveAdjustmentBatches;
use Civi\WMFBatch\FinanceConstants;
use Civi\Api4\WMFAudit;

/**
 * @group WmfAudit
 */
class ReserveAdjustmentBatchesTest extends BaseAuditTestCase {

  protected string $gateway = '';

  private const ORIGINAL_BATCH = 'adyen_350_USD';

  public function tearDown(): void {
    Batch::delete(FALSE)->addWhere('name', 'LIKE', 'adjustment_%')->execute();
    Batch::delete(FALSE)->addWhere('name', '=', self::ORIGINAL_BATCH)->execute();
    parent::tearDown();
  }

  /**
   * A correction to an already-exported batch gets a batch of its own, under
   * the Adjustment Batch mode, carrying the original's gateway and the
   * contribution's own settled amount and currency.
   */
  public function testPendingAdjustmentGetsABatch(): void {
    $adjustmentID = $this->createAdjustment();

    $result = WMFAudit::reserveAdjustmentBatches(FALSE)->execute();

    $expectedName = 'adjustment_' . $adjustmentID . '_USD';
    $this->assertCount(1, $result);
    $this->assertEquals($expectedName, $result[0]['batch']);

    $batch = Batch::get(FALSE)
      ->addWhere('name', '=', $expectedName)
      ->addSelect('*', 'status_id:name', 'mode_id:name', 'batch_data.*')
      ->execute()->single();
    $this->assertEquals('Adjustment Batch', $batch['mode_id:name']);
    $this->assertEquals('Open', $batch['status_id:name']);
    $this->assertEquals(1, $batch['item_count']);
    $this->assertEquals('USD', $batch['batch_data.settlement_currency']);
    $this->assertEquals(10, $batch['batch_data.settled_donation_amount']);
    // Carried over from the original batch so the journal keeps its vendor
    // and location dimensions.
    $this->assertEquals('adyen', $batch['batch_data.settlement_gateway']);

    $this->assertEquals($expectedName, $this->getAdjustment($adjustmentID)['adjustment_batch_reference']);
  }

  /**
   * Re-running must not hand the same adjustment a second batch - this is
   * meant to be safe to put on a cron.
   */
  public function testAdjustmentWithABatchIsSkipped(): void {
    $this->createAdjustment();
    WMFAudit::reserveAdjustmentBatches(FALSE)->execute();

    $secondRun = WMFAudit::reserveAdjustmentBatches(FALSE)->execute();

    $this->assertCount(0, $secondRun, 'An adjustment that already has a batch should not be picked up again');
    $batches = Batch::get(FALSE)->addWhere('name', 'LIKE', 'adjustment_%')->execute();
    $this->assertCount(1, $batches, 'Expected exactly one adjustment batch after two runs');
  }

  /**
   * There is nothing to reverse without a settled amount, so the adjustment
   * is flagged rather than given a batch - and the run carries on.
   */
  public function testAdjustmentWithoutSettlementNeedsAttention(): void {
    $contributionID = $this->createTestEntity('Contribution', [
      'receive_date' => date('Y-m-d H:i:s'),
      'total_amount' => 10,
      'currency' => 'USD',
      'financial_type_id:name' => 'Cash',
      'contact_id' => $this->createIndividual(),
    ])['id'];
    $adjustmentID = $this->createAdjustment($contributionID);

    $result = WMFAudit::reserveAdjustmentBatches(FALSE)->execute();

    $this->assertCount(1, $result);
    $this->assertNull($result[0]['batch']);
    $adjustment = $this->getAdjustment($adjustmentID);
    $this->assertEquals('needs_attention', $adjustment['status']);
    $this->assertStringContainsString('no settlement record', $adjustment['status_note']);
  }

  /**
   * While another process holds this adjustment's lock, it must be left
   * pending and unreserved rather than reserved a second time.
   */
  public function testLockedAdjustmentIsLeftForTheNextRun(): void {
    $adjustmentID = $this->createAdjustment();
    $competingLock = $this->acquireCompetingLock(FinanceConstants::ADJUSTMENT_LOCK_PREFIX . $adjustmentID);

    $result = WMFAudit::reserveAdjustmentBatches(FALSE)->execute();

    $this->assertCount(1, $result);
    $this->assertNull($result[0]['batch']);
    $this->assertStringContainsString('locked', $result[0]['message']);
    $this->assertCount(0, Batch::get(FALSE)->addWhere('name', 'LIKE', 'adjustment_%')->execute());
    $adjustment = $this->getAdjustment($adjustmentID);
    $this->assertEquals('pending', $adjustment['status'], 'Still pending, so the next run picks it up');
    $this->assertEmpty($adjustment['adjustment_batch_reference']);

    $competingLock->disconnect();
  }

  /**
   * Holding the lock is only half of it - the pending list is a snapshot, so
   * a run that gets the lock after another one has finished with it must
   * re-read the row rather than trust what it queried.
   */
  public function testAdjustmentReservedSinceTheQueryIsNotReservedTwice(): void {
    $adjustmentID = $this->createAdjustment();
    // Stand in for the competing run having got there first: the row is
    // already reserved by the time we take the lock.
    CustomValue::update('FinancialDataAdjustment', FALSE)
      ->addWhere('id', '=', $adjustmentID)
      ->addValue('adjustment_batch_reference', 'adjustment_' . $adjustmentID . '_USD')
      ->execute();

    $action = WMFAudit::reserveAdjustmentBatches(FALSE);
    $reserved = $this->invokeReserveUnderLock($action, $adjustmentID);

    $this->assertNull($reserved['batch']);
    $this->assertStringContainsString('already handled', $reserved['message']);
    $this->assertCount(0, Batch::get(FALSE)->addWhere('name', 'LIKE', 'adjustment_%')->execute());
  }

  /**
   * Reach past the pending-list query to the per-adjustment step, which is
   * where the re-read lives - the query itself would filter this row out.
   */
  private function invokeReserveUnderLock(ReserveAdjustmentBatches $action, int $adjustmentID): array {
    $method = new \ReflectionMethod($action, 'reserveUnderLock');
    $method->setAccessible(TRUE);
    return $method->invoke($action, $adjustmentID);
  }

  /**
   * A settled contribution, an exported batch it was settled into, and a
   * pending correction against it. Returns the adjustment's id.
   */
  private function createAdjustment(?int $contributionID = NULL): int {
    if ($contributionID === NULL) {
      $contributionID = $this->createTestEntity('Contribution', [
        'receive_date' => date('Y-m-d H:i:s'),
        'total_amount' => 10,
        'currency' => 'USD',
        'financial_type_id:name' => 'Cash',
        'contact_id' => $this->createIndividual(),
        'contribution_settlement.settlement_batch_reference' => self::ORIGINAL_BATCH,
        'contribution_settlement.settled_donation_amount' => 10.00,
        'contribution_settlement.settlement_currency' => 'USD',
        'contribution_settlement.settlement_date' => '2026-02-05',
      ])['id'];
    }
    $this->createTestEntity('Batch', [
      'name' => self::ORIGINAL_BATCH,
      'mode_id:name' => 'Automatic Batch',
      'status_id:name' => 'Exported',
      'item_count' => 1,
      'batch_data.settlement_currency' => 'USD',
      'batch_data.settlement_date' => '2026-02-05',
      'batch_data.settlement_gateway' => 'adyen',
    ], self::ORIGINAL_BATCH);

    return CustomValue::create('FinancialDataAdjustment', FALSE)
      ->setValues([
        'entity_id' => $contributionID,
        'gl_code_before' => '43481',
        'gl_code_after' => '43484',
        'is_endowment_before' => FALSE,
        'is_endowment_after' => FALSE,
        'status' => 'pending',
        'original_batch' => self::ORIGINAL_BATCH,
        'created_date' => date('Y-m-d H:i:s'),
        'modified_date' => date('Y-m-d H:i:s'),
      ])->execute()->first()['id'];
  }

  private function getAdjustment(int $id): array {
    return (array) CustomValue::get('FinancialDataAdjustment', FALSE)
      ->addWhere('id', '=', $id)
      ->execute()->single();
  }

}

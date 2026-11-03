<?php

namespace Civi\Api4\Action\WMFAudit;

use Civi\Api4\Batch;
use Civi\Api4\Contribution;
use Civi\Api4\CustomValue;
use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\Generic\Result;
use Civi\WMFBatch\FinanceConstants;

/**
 * Give every pending FinancialDataAdjustment a batch to be journaled in.
 *
 * A contribution whose GL code or endowment flag is corrected after its
 * settlement batch was exported leaves a FinancialDataAdjustment row behind
 * (see Civi\WMFHook\FinancialDataChange). Intacct already has a journal for
 * the old values, so the correction has to be reversed and re-journaled
 * rather than simply regenerated - and that needs a batch of its own, since
 * the settlement batch it came from is closed.
 *
 * One batch per adjustment row for now, named adjustment_{id}_{currency} and
 * under its own Adjustment Batch mode so WMFAudit.GenerateBatch leaves it be.
 *
 * Reserving only - this writes the batch record and points the adjustment at
 * it, and stops. Generating the journals into that batch, and pushing them,
 * are per-batch jobs and live in the Batch namespace.
 */
class ReserveAdjustmentBatches extends AbstractAction {

  /**
   * @param Result $result
   *   One record per adjustment considered: its id, the batch reserved for it
   *   (or NULL) and, when nothing was reserved, why not.
   *
   * @throws \CRM_Core_Exception
   */
  public function _run(Result $result): void {
    foreach ($this->getPendingAdjustments() as $adjustment) {
      $result[] = ['id' => $adjustment['id']] + $this->reserveUnderLock($adjustment['id']);
    }
  }

  /**
   * Adjustments waiting for a batch.
   *
   * original_batch is only set when the correction landed on a batch that was
   * already Exported - a null one means Intacct never saw the old values, so
   * there is nothing to reverse. An adjustment that already has a batch is
   * left alone, which is what makes this safe to re-run.
   *
   * @throws \CRM_Core_Exception
   */
  private function getPendingAdjustments(): iterable {
    return CustomValue::get('FinancialDataAdjustment', FALSE)
      ->addWhere('status', '=', 'pending')
      ->addWhere('original_batch', 'IS NOT NULL')
      ->addWhere('adjustment_batch_reference', 'IS NULL')
      ->addOrderBy('id')
      ->execute();
  }

  /**
   * Reserve one adjustment, holding its lock while we do.
   *
   * The pending list this was read from is a snapshot, so taking the lock is
   * only half of it - another run may have reserved this adjustment between
   * the query and here, and would have released the lock before we got it.
   * Hence the re-read inside the lock: the row as it is now, not as it was.
   *
   * @throws \CRM_Core_Exception
   */
  private function reserveUnderLock(int $adjustmentID): array {
    $lock = \Civi::lockManager()->acquire(FinanceConstants::ADJUSTMENT_LOCK_PREFIX . $adjustmentID);
    if (!$lock->isAcquired()) {
      // Another run has it. Leave it pending - the next run picks it up.
      return ['batch' => NULL, 'message' => 'locked by another reservation run'];
    }
    try {
      $adjustment = CustomValue::get('FinancialDataAdjustment', FALSE)
        ->addWhere('id', '=', $adjustmentID)
        ->execute()->first();
      if (!$adjustment || $adjustment['status'] !== 'pending' || !empty($adjustment['adjustment_batch_reference'])) {
        return ['batch' => NULL, 'message' => 'already handled by another reservation run'];
      }
      return $this->reserveBatch($adjustment);
    }
    finally {
      $lock->release();
    }
  }

  /**
   * Create this adjustment's batch and point the adjustment at it.
   *
   * @throws \CRM_Core_Exception
   */
  private function reserveBatch(array $adjustment): array {
    $settlement = $this->getSettlement($adjustment['entity_id']);
    if (!$settlement) {
      return $this->flagForAttention($adjustment, 'contribution has no settlement record to reverse');
    }
    $originalBatch = Batch::get(FALSE)
      ->addWhere('name', '=', $adjustment['original_batch'])
      ->addSelect('batch_data.settlement_gateway')
      ->execute()->first();
    if (!$originalBatch) {
      return $this->flagForAttention($adjustment, 'original batch ' . $adjustment['original_batch'] . ' no longer exists');
    }

    $currency = $settlement['contribution_settlement.settlement_currency'];
    $batchName = 'adjustment_' . $adjustment['id'] . '_' . $currency;
    Batch::save(FALSE)
      ->addRecord([
        'name' => $batchName,
        'status_id:name' => 'Open',
        'type_id:name' => 'Contribution',
        'mode_id:name' => 'Adjustment Batch',
        'item_count' => 1,
        'total' => $settlement['contribution_settlement.settled_donation_amount'],
        'batch_data.settled_donation_amount' => $settlement['contribution_settlement.settled_donation_amount'],
        'batch_data.settlement_currency' => $currency,
        // The correction's own date, not the original settlement's - that
        // period is closed in Intacct by the time we get here.
        'batch_data.settlement_date' => $adjustment['created_date'],
        // Carried over so the journal keeps the original's vendor/location
        // dimensions - the money is still sitting in that gateway's account.
        'batch_data.settlement_gateway' => $originalBatch['batch_data.settlement_gateway'],
      ])
      ->setMatch(['name', 'type_id'])
      ->execute();

    CustomValue::update('FinancialDataAdjustment', FALSE)
      ->addWhere('id', '=', $adjustment['id'])
      ->addValue('adjustment_batch_reference', $batchName)
      ->addValue('modified_date', date('Y-m-d H:i:s'))
      ->execute();

    return ['batch' => $batchName, 'message' => NULL];
  }

  /**
   * @throws \CRM_Core_Exception
   */
  private function getSettlement(int $contributionID): ?array {
    return Contribution::get(FALSE)
      ->addWhere('id', '=', $contributionID)
      ->addWhere('contribution_settlement.settlement_currency', 'IS NOT NULL')
      ->addSelect('contribution_settlement.settled_donation_amount', 'contribution_settlement.settlement_currency')
      ->execute()->first();
  }

  /**
   * @throws \CRM_Core_Exception
   */
  private function flagForAttention(array $adjustment, string $reason): array {
    CustomValue::update('FinancialDataAdjustment', FALSE)
      ->addWhere('id', '=', $adjustment['id'])
      ->addValue('status', 'needs_attention')
      ->addValue('status_note', $reason)
      ->addValue('modified_date', date('Y-m-d H:i:s'))
      ->execute();
    \Civi::log('wmf')->alert(
      'generate_adjustment_batch: adjustment {id} needs review - {reason}',
      ['id' => $adjustment['id'], 'reason' => $reason]
    );
    return ['batch' => NULL, 'message' => $reason];
  }

}

<?php

namespace Civi\Api4\Action\Batch;

use Civi\Api4\Batch;
use Civi\WMFAudit\BaseAuditTestCase;

/**
 * @group Adyen
 * @group WmfAudit
 */
class ValidateTest extends BaseAuditTestCase {

  protected string $gateway = '';

  public function tearDown(): void {
    Batch::delete(FALSE)->addWhere('name', 'LIKE', 'adyen_419%')->execute();
    parent::tearDown();
  }

  /**
   * T419097: an Exported batch that no longer reconciles (e.g. a donation
   * was added to CiviCRM after the batch was exported) should be flagged
   * needs_attention, not left looking trustworthy just because it is old.
   */
  public function testExportedBatchThatNoLongerReconcilesIsFlagged(): void {
    $batchName = 'adyen_4190_USD';
    $this->createQueueContribution($batchName, 10.00, 'USD', '2026-01-26');
    $batchId = $this->createBatchEntity($batchName, 'USD', '2026-01-26', 10.00, 1, 'Exported');

    // Simulate a donation having been added after export: the live SQL total
    // (10, from the one contribution above) no longer matches the total the
    // batch was exported with (30).
    Batch::update(FALSE)
      ->addWhere('id', '=', $batchId)
      ->setValues([
        'batch_data.settled_net_amount' => 30.00,
        'batch_data.settled_donation_amount' => 30.00,
      ])->execute();

    Batch::validate(FALSE)->addWhere('id', '=', $batchId)->execute();

    $batch = Batch::get(FALSE)->addSelect('status_id:name')->addWhere('id', '=', $batchId)->execute()->single();
    $this->assertEquals('needs_attention', $batch['status_id:name'], 'A previously-Exported batch that no longer reconciles should be flagged for attention');
  }

  private function createQueueContribution(string $batchName, float $amount, string $currency, string $settlementDate): void {
    $this->createTestEntity('Contribution', [
      'receive_date' => date('Y-m-d H:i:s'),
      'total_amount' => $amount,
      'currency' => $currency,
      'financial_type_id:name' => 'Cash',
      'contact_id' => $this->createIndividual(),
      'Gift_Data.Channel' => 'Mobile Banner',
      'Gift_Data.Fund' => 'Unrestricted',
      'Gift_Data.is_major_gift' => 0,
      'contribution_settlement.settlement_batch_reference' => $batchName,
      'contribution_settlement.settled_donation_amount' => $amount,
      'contribution_settlement.settlement_currency' => $currency,
      'contribution_settlement.settlement_date' => $settlementDate,
    ]);
  }

  private function createBatchEntity(string $batchName, string $currency, string $settlementDate, float $net, int $itemCount, string $status): int {
    return $this->createTestEntity('Batch', [
      'name' => $batchName,
      'mode_id:name' => 'Automatic Batch',
      'status_id:name' => $status,
      'item_count' => $itemCount,
      'batch_data.settlement_currency' => $currency,
      'batch_data.settlement_date' => $settlementDate,
      'batch_data.settled_donation_amount' => $net,
      'batch_data.settled_reversal_amount' => 0,
      'batch_data.settled_fee_amount' => 0,
      'batch_data.settled_net_amount' => $net,
      'batch_data.last_successful_total_verification_date' => date('Y-m-d H:i:s'),
    ], $batchName)['id'];
  }

}

<?php

namespace Civi\WMFAudit;

use Civi\Api4\Batch;
use Civi\Api4\CustomValue;
use Civi\Api4\WMFAudit;
use League\Csv\Reader;

/**
 * @group WmfAudit
 */
class GenerateAdjustmentJournalsTest extends BaseAuditTestCase {

  protected string $gateway = '';

  private const ORIGINAL_BATCH = 'adyen_351_USD';

  public function tearDown(): void {
    Batch::delete(FALSE)->addWhere('name', 'LIKE', 'adjustment_%')->execute();
    Batch::delete(FALSE)->addWhere('name', '=', self::ORIGINAL_BATCH)->execute();
    parent::tearDown();
  }

  /**
   * A GL recode within one entity is a single two-line journal - debit the
   * account it was on, credit the one it should be on. No contra line: the
   * cash never moved, only how the revenue was coded.
   */
  public function testGlCodeChangeIsOneBalancedJournal(): void {
    $batchName = $this->reserve(['gl_code_before' => '43481', 'gl_code_after' => '43484']);

    $journals = Batch::generateAdjustmentJournals(FALSE)->setName($batchName)->execute()->first();

    $this->assertEquals(['foundation'], array_keys($journals['csv']));
    $journal = $journals['csv']['foundation'];
    $this->assertEquals('foundation', $journal['instance']);
    $this->assertEquals('', $journal['suffix']);

    $rows = $this->readRows($journal['file']);
    $this->assertCount(2, $rows);
    $this->assertRow($rows[0], '43481', '10.00', '0.00', '100-WMF', 'CC-1005');
    $this->assertRow($rows[1], '43484', '0.00', '10.00', '100-WMF', 'CC-1005');
    $this->assertBalances($rows);
    // Vendor carried from the original batch's gateway, not from the
    // "adjustment" prefix on this batch's own name.
    $this->assertEquals('V01670', $rows[0]['GLENTRY_VENDORID']);
  }

  /**
   * Flipping to endowment is the cross-instance transfer that settlement
   * would have made: reverse the revenue on wmf against the due-to-endowment
   * account, book it on endowment against the due-from-wmf account.
   */
  public function testEndowmentFlipIsATransferPair(): void {
    $batchName = $this->reserve([
      'gl_code_before' => '43481',
      'gl_code_after' => '43481',
      'is_endowment_before' => FALSE,
      'is_endowment_after' => TRUE,
    ]);

    $journals = Batch::generateAdjustmentJournals(FALSE)->setName($batchName)->execute()->first();

    $this->assertEquals(['to_endowment', 'endowment_instance'], array_keys($journals['csv']));
    $this->assertEquals('foundation', $journals['csv']['to_endowment']['instance']);
    $this->assertEquals('endowment', $journals['csv']['endowment_instance']['instance']);

    $from = $this->readRows($journals['csv']['to_endowment']['file']);
    $this->assertRow($from[0], '43481', '10.00', '0.00', '100-WMF', 'CC-1005');
    // 27375 = GL_BALANCING_ACCOUNT_WMF_INSTANCE.
    $this->assertRow($from[1], '27375', '0.00', '10.00', '100-WMF', 'CC-1005');
    $this->assertBalances($from);

    $to = $this->readRows($journals['csv']['endowment_instance']['file']);
    // Endowment journals carry no cost centre.
    $this->assertRow($to[0], '43481', '0.00', '10.00', '300-END', '');
    // 11110 = GL_BALANCING_ACCOUNT_ENDOWMENT_INSTANCE.
    $this->assertRow($to[1], '11110', '10.00', '0.00', '300-END', '');
    $this->assertBalances($to);
  }

  /**
   * Both changing needs no special case - wmf reverses the old code and
   * endowment books the new one.
   */
  public function testGlCodeAndEndowmentChangeTogether(): void {
    $batchName = $this->reserve([
      'gl_code_before' => '43481',
      'gl_code_after' => '43484',
      'is_endowment_before' => FALSE,
      'is_endowment_after' => TRUE,
    ]);

    $journals = Batch::generateAdjustmentJournals(FALSE)->setName($batchName)->execute()->first();

    $from = $this->readRows($journals['csv']['to_endowment']['file']);
    $to = $this->readRows($journals['csv']['endowment_instance']['file']);
    $this->assertEquals('43481', $from[0]['ACCT_NO'], 'wmf reverses the code it was booked under');
    $this->assertEquals('43484', $to[0]['ACCT_NO'], 'endowment books the corrected code');
  }

  /**
   * On the way to endowment a major gift is restricted whatever it was on
   * the foundation side - matching writeCrossInstanceTransfer().
   */
  public function testMajorGiftIsRestrictedOnTheEndowmentSide(): void {
    $batchName = $this->reserve([
      // 43485 = Major Gifts - Unrestricted.
      'gl_code_before' => '43481',
      'gl_code_after' => '43485',
      'is_endowment_before' => FALSE,
      'is_endowment_after' => TRUE,
    ]);

    $journals = Batch::generateAdjustmentJournals(FALSE)->setName($batchName)->execute()->first();

    $to = $this->readRows($journals['csv']['endowment_instance']['file']);
    // 43428 = Major Gifts - Restricted.
    $this->assertEquals('43428', $to[0]['ACCT_NO']);
  }

  /**
   * The cost centre follows each line's own account, so recoding into a
   * major gifts account moves it - the reversal keeps CC-1005, the new line
   * takes CC-1004. The contribution's own is_major_gift flag is not
   * consulted, and here says otherwise.
   */
  public function testCostCentreFollowsEachLinesAccount(): void {
    $batchName = $this->reserve(['gl_code_before' => '43481', 'gl_code_after' => '43485']);

    $journals = Batch::generateAdjustmentJournals(FALSE)->setName($batchName)->execute()->first();

    $rows = $this->readRows($journals['csv']['foundation']['file']);
    $this->assertRow($rows[0], '43481', '10.00', '0.00', '100-WMF', 'CC-1005');
    $this->assertRow($rows[1], '43485', '0.00', '10.00', '100-WMF', 'CC-1004');
  }

  /**
   * Settled contribution, exported batch, pending correction, reserved.
   * Returns the adjustment batch's name.
   */
  private function reserve(array $adjustment): string {
    $contributionID = $this->createTestEntity('Contribution', [
      'receive_date' => date('Y-m-d H:i:s'),
      'total_amount' => 10,
      'currency' => 'USD',
      'financial_type_id:name' => 'Cash',
      'contact_id' => $this->createIndividual(),
      'Gift_Data.Channel' => 'Mobile Banner',
      'Gift_Data.Fund' => 'Unrestricted',
      'Gift_Data.is_major_gift' => 0,
      'contribution_settlement.settlement_batch_reference' => self::ORIGINAL_BATCH,
      'contribution_settlement.settled_donation_amount' => 10.00,
      'contribution_settlement.settlement_currency' => 'USD',
      'contribution_settlement.settlement_date' => '2026-02-05',
    ])['id'];
    $this->createTestEntity('Batch', [
      'name' => self::ORIGINAL_BATCH,
      'mode_id:name' => 'Automatic Batch',
      'status_id:name' => 'Exported',
      'item_count' => 1,
      'batch_data.settlement_currency' => 'USD',
      'batch_data.settlement_date' => '2026-02-05',
      'batch_data.settlement_gateway' => 'adyen',
    ], self::ORIGINAL_BATCH);
    CustomValue::create('FinancialDataAdjustment', FALSE)
      ->setValues($adjustment + [
        'entity_id' => $contributionID,
        'gl_code_before' => '43481',
        'gl_code_after' => '43481',
        'is_endowment_before' => FALSE,
        'is_endowment_after' => FALSE,
        'status' => 'pending',
        'original_batch' => self::ORIGINAL_BATCH,
        'created_date' => date('Y-m-d H:i:s'),
        'modified_date' => date('Y-m-d H:i:s'),
      ])->execute();
    return WMFAudit::reserveAdjustmentBatches(FALSE)->execute()->first()['batch'];
  }

  private function readRows(string $file): array {
    $reader = Reader::from($file);
    $reader->setHeaderOffset(0);
    return array_values(iterator_to_array($reader));
  }

  private function assertRow(array $row, string $account, string $debit, string $credit, string $location, string $dept): void {
    $this->assertEquals($account, $row['ACCT_NO']);
    $this->assertEquals($debit, $row['DEBIT']);
    $this->assertEquals($credit, $row['CREDIT']);
    $this->assertEquals($location, $row['LOCATION_ID']);
    $this->assertEquals($dept, $row['DEPT_ID']);
  }

  /**
   * PushJournal refuses an unbalanced journal, so every one we emit has to
   * add up on its own.
   */
  private function assertBalances(array $rows): void {
    $debit = $credit = 0;
    foreach ($rows as $row) {
      $debit += (float) $row['DEBIT'];
      $credit += (float) $row['CREDIT'];
    }
    $this->assertEquals($debit, $credit, 'Journal must balance');
  }

}

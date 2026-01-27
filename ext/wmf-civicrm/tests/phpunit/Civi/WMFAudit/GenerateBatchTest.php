<?php

namespace Civi\WMFAudit;

use Civi\API\Exception\UnauthorizedException;
use Civi\Api4\Batch;
use Civi\Api4\Contribution;
use Civi\Api4\Generic\Result;
use Civi\Api4\WMFAudit;

/**
 * @group Adyen
 * @group WmfAudit
 */
class GenerateBatchTest extends BaseAuditTestCase {
  protected string $gateway = '';

  public function tearDown():void {
    Batch::delete(FALSE)
      ->addWhere('name', 'LIKE', 'adyen_33%')
      ->execute();
    parent::tearDown();
  }

  public function testChannelsWithSameGlCodeAreGroupedIntoSingleJournalRow(): void {
    $prefix = 'adyen_333';
    $batchName = "{$prefix}_USD";
    $currency = 'USD';
    $settlementDate = '2026-01-20';

    // Mobile Banner / Desktop Banner / Other Banner => all map to ACCT_NO 43481 in getAccountClause().
    $this->createContribution([
      'Gift_Data.Channel' => 'Mobile Banner',
      'Gift_Data.Fund' => 'Unrestricted',
      'Gift_Data.is_major_gift' => 0,
      'contribution_settlement.settlement_batch_reference' => $batchName,
      'contribution_settlement.settled_donation_amount' => 10.00,
      'contribution_settlement.settlement_currency' => $currency,
      'contribution_settlement.settlement_date' => $settlementDate,
    ]);
    $this->createContribution([
      'Gift_Data.Channel' => 'Desktop Banner',
      'Gift_Data.Fund' => 'Unrestricted',
      'Gift_Data.is_major_gift' => 0,
      'contribution_settlement.settlement_batch_reference' => $batchName,
      'contribution_settlement.settled_donation_amount' => 20.00,
      'contribution_settlement.settlement_currency' => $currency,
      'contribution_settlement.settlement_date' => $settlementDate,
    ]);
    $this->createContribution([
      'Gift_Data.channel' => 'Other Banner',
      'Gift_Data.fund' => 'Unrestricted',
      'Gift_Data.is_major_gift' => 0,
      'contribution_settlement.settlement_batch_reference' => $batchName,
      'contribution_settlement.settled_donation_amount' => 30.00,
      'contribution_settlement.settlement_currency' => $currency,
      'contribution_settlement.settlement_date' => $settlementDate,
    ]);
    $result = $this->runGenerate($batchName, $currency, $settlementDate, 60, 3, $prefix);

    $this->assertNotEmpty($result, 'Expected at least one batch result');
    $batchResult = $result[0];
    $this->assertEquals($batchName, $batchResult['batch']['name']);

    $rows = $batchResult['csv_rows'] ?? [];
    $this->assertNotEmpty($rows, 'Expected csv_rows in result (setIsOutputRows(TRUE))');

    // Find donation row(s) for ACCT_NO 43481.
    $matches = array_values(array_filter($rows, function ($r) {
      return (string) ($r['ACCT_NO'] ?? '') === '43481'
        && str_ends_with((string) ($r['MEMO'] ?? ''), 'Donations');
    }));

    // This is the core behavior: same GL code channels group into ONE row (given same Fund + is_major_gift).
    $this->assertCount(1, $matches, 'Expected a single grouped journal row for 43481 donations');

    $row = $matches[0];
    $this->assertEquals('60.00', (string) $row['CREDIT'], 'Expected grouped credit to equal sum of donation amounts');
    $this->assertEquals('0.00', (string) $row['DEBIT'], 'Expected donations to be credit-only in this dataset');
    $this->assertSame(3, $this->memoCount($row), 'Expected MEMO count to equal number of contributions grouped');
  }

  public function testSameGlCodeDoesNotGroupAcrossDifferentFunds(): void {
    $prefix = 'adyen_335';
    $batchName = "{$prefix}_USD";
    $currency = 'USD';
    $settlementDate = '2026-01-21';

    // Both channels map to 43484, but different Fund => GROUP BY Fund, ACCT_NO, is_major_gift => should become 2 rows.
    $this->createContribution([
      'Gift_Data.Channel' => 'SMS', // 43484
      'Gift_Data.Fund' => 'Unrestricted',
      'Gift_Data.is_major_gift' => 0,
      'contribution_settlement.settlement_batch_reference' => $batchName,
      'contribution_settlement.settled_donation_amount' => 10.00,
      'contribution_settlement.settlement_currency' => $currency,
      'contribution_settlement.settlement_date' => $settlementDate,
    ]);
    $this->createContribution([
      'Gift_Data.Channel' => 'Other Online', // 43484
      'Gift_Data.Fund' => 'Restricted - Foo', // different Fund => separate group
      'Gift_Data.is_major_gift' => 0,
      'contribution_settlement.settlement_batch_reference' => $batchName,
      'contribution_settlement.settled_donation_amount' => 20.00,
      'contribution_settlement.settlement_currency' => $currency,
      'contribution_settlement.settlement_date' => $settlementDate,
    ]);

    $result = $this->runGenerate($batchName, $currency, $settlementDate, 30.00, 2, $prefix);

    $rows = $result[0]['csv_rows'] ?? [];

    $matches = array_values(array_filter($rows, function ($r) {
      return (string) ($r['ACCT_NO'] ?? '') === '43484'
        && str_ends_with((string) ($r['MEMO'] ?? ''), 'Donations');
    }));

    $this->assertCount(2, $matches, 'Expected two donation rows for 43484 because Fund differs (GROUP BY Fund, ACCT_NO, is_major_gift)');
  }

  /**
   * Optional “guardrail” test: unknown/unmapped channel should produce blank ACCT_NO in SQL,
   * which is later treated as incomplete (prevents close).
   *
   * This keeps the grouping tests honest: grouping only works for mapped channels.
   */
  public function testUnmappedChannelProducesBlankAcctNoAndPreventsClosing(): void {
    $prefix = 'adyen_336';
    $batchName = "{$prefix}_USD";
    $currency = 'USD';
    $settlementDate = '2026-01-22';

    $this->createContribution([
      'Gift_Data.Channel' => 'Totally Unknown Channel',
      'Gift_Data.Fund' => 'Unrestricted',
      'Gift_Data.is_major_gift' => 0,
      'contribution_settlement.settlement_batch_reference' => $batchName,
      'contribution_settlement.settled_donation_amount' => 10.00,
      'contribution_settlement.settlement_currency' => $currency,
      'contribution_settlement.settlement_date' => $settlementDate,
    ]);

    $result = $this->runGenerate($batchName, $currency, $settlementDate, 10.00, 1, $prefix);

    $rows = $result[0]['csv_rows'] ?? [];
    $this->assertNotEmpty($rows);

    $donationRows = array_values(array_filter($rows, fn($r) => str_ends_with((string) ($r['MEMO'] ?? ''), 'Donations')));
    $this->assertNotEmpty($donationRows);

    // When getAccountClause() falls through, ACCT_NO is ''.
    $this->assertSame('', (string) ($donationRows[0]['ACCT_NO'] ?? ''), 'Expected unmapped channel to yield blank ACCT_NO');
  }

  /**
   * Create a total_verified Automatic Batch with the minimal batch_data fields.
   */
  private function createBatch(string $batchName, string $currency, string $settlementDate, float $net, int $itemCount): void {
    $this->createTestEntity('Batch', [
      'name' => $batchName,
      'mode_id:name' => 'Automatic Batch',
      'status_id:name' => 'total_verified',
      'item_count' => $itemCount,

      // Totals used in GenerateBatch expected/validation:
      'batch_data.settlement_currency' => $currency,
      'batch_data.settlement_date' => $settlementDate,
      'batch_data.settled_donation_amount' => $net,
      'batch_data.settled_reversal_amount' => 0,
      'batch_data.settled_fee_amount' => 0,
      'batch_data.settled_net_amount' => $net,
    ], $batchName);
  }

  /**
   * Create a contribution and set the Gift Data + Settlement custom fields entirely via API4.
   */
  private function createContribution(array $spec): void {
    $defaults = [
      'receive_date' => date('Y-m-d H:i:s'),
      'total_amount' => 10.0,
      'currency' => 'USD',
      'financial_type_id:name' => 'Cash',
      'contact_id' => $this->createIndividual(), // BaseAuditTestCase usually provides something like this.

      'Gift_Data.Channel' => 'Mobile Banner',
      'Gift_Data.Fund' => 'Unrestricted',
      'Gift_Data.is_major_gift' => 0,

      // Settlement (custom group civicrm_value_contribution_settlement):
      'contribution_settlement.settlement_batch_reference' => NULL,
      'contribution_settlement.settlement_batch_reversal_reference' => NULL,
      'contribution_settlement.settled_donation_amount' => 10.0,
      'contribution_settlement.settled_reversal_amount' => 0.0,
      'contribution_settlement.settled_fee_amount' => 0.0,
      'contribution_settlement.settled_fee_reversal_amount' => 0.0,
      'contribution_settlement.settlement_date' => date('Y-m-d'),
      'contribution_settlement.settlement_currency' => 'USD',
    ];
    $this->createTestEntity('Contribution', array_merge($defaults, $spec));
  }

  /**
   * Parse the " | " memo into parts and return the COUNT(*) part if present.
   * Memo pattern in SQL:
   *   "<prefix> | <currency> | <start> | <end> | <COUNT> | Donations"
   */
  private function memoCount(array $row): ?int {
    if (empty($row['MEMO'])) {
      return NULL;
    }
    $parts = explode(' | ', $row['MEMO']);
    if (isset($parts[4]) && is_numeric($parts[4])) {
      return (int) $parts[4];
    }
    return NULL;
  }

  /**
   * @param string $batchName
   * @param string $currency
   * @param string $settlementDate
   * @param string $prefix
   *
   * @return \Civi\Api4\Generic\Result
   */
  public function runGenerate(string $batchName, string $currency, string $settlementDate, float $amount, $itemCount, string $prefix): Result {
    // Batch expected totals must match what GenerateBatch will compute, or it flags discrepancy.
    $this->createBatch($batchName, $currency, $settlementDate, $amount, $itemCount);
    try {
      return WMFAudit::generateBatch(FALSE)
        ->setBatchPrefix($prefix)
        ->setIsDryRun(TRUE)
        ->setIsOutputRows(TRUE)
        ->setIsOutputCsv(FALSE)
        ->execute();
    }
    catch (\CRM_Core_Exception $e) {
      $this->fail($e->getMessage());
    }
  }

}

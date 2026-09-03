<?php

namespace phpunit\Civi\WMFAudit;

use Civi\Api4\Batch;
use Civi\Api4\Contribution;
use Civi\Api4\ContributionSoft;
use Civi\WMFAudit\BaseAuditTestCase;

/**
 * @group WmfAudit
 */
class OverflowAuditTest extends BaseAuditTestCase {

  protected string $gateway = 'overflow';

  protected string $file = 'overflow-6a8508f7cdc7f0c4c50d9182.json';

  protected string $batchName = 'overflow_OVRFLW-S322222226JMLQ2_USD';

  protected string $endowmentFile = 'overflow-endowment-6a8508f7cdc7f0c4c50d9199.json';

  protected string $endowmentBatchName = 'overflow_OVRFLW-S322222226ENDOW_USD';

  protected string $dafFile = 'overflow-daf-6a8508f7cdc7f0c4c50d92af.json';

  protected string $dafBatchName = 'overflow_OVRFLW-S322222226DAF01_USD';

  /**
   * Overflow test fixtures sit flat in data/Overflow, without the
   * gateway/incoming nesting used by gateways with recurring live jobs.
   *
   * @var bool
   */
  protected bool $useIncomingDirectory = FALSE;

  public function tearDown(): void {
    Contribution::delete(FALSE)
      ->addWhere('contribution_settlement.settlement_batch_reference', 'IN', [$this->batchName, $this->endowmentBatchName, $this->dafBatchName])
      ->execute();
    Batch::delete(FALSE)
      ->addWhere('name', 'IN', [$this->batchName, $this->endowmentBatchName, $this->dafBatchName])
      ->execute();
    $this->cleanupContact(['email_primary.email' => 'jane.mouse@example.org']);
    $this->cleanupContact(['email_primary.email' => 'marge.endow@example.org']);
    $this->cleanupContact(['email_primary.email' => 'bart.daf@example.org']);
    parent::tearDown();
  }

  public function createTransactionLog(array $row): void {}

  public function getRows(string $directory, string $fileName): array {
    return [];
  }

  public function testStockGiftContributionIsCreated(): void {
    $this->runAuditBatch('', $this->file);

    $contribution = Contribution::get(FALSE)
      ->setSelect([
        '*',
        'contact_id.display_name',
        'contact_id.first_name',
        'contact_id.last_name',
        'payment_instrument_id:name',
        'financial_type_id:name',
        'contribution_extra.*',
        'contribution_settlement.*',
        'Stock_Information.*',
        'Gift_Data.Channel',
        'Gift_Data.Fund',
        'Gift_Data.is_major_gift',
      ])
      ->addWhere('contribution_settlement.settlement_batch_reference', '=', $this->batchName)
      ->execute()->single();

    $this->assertSame('Jane Mouse', $contribution['contact_id.display_name']);
    $this->assertSame('Stock', $contribution['payment_instrument_id:name']);
    $this->assertSame('Stock', $contribution['financial_type_id:name']);
    $this->assertSame('OVERFLOW 6a7c9a6e7027ca16774e6f76', $contribution['trxn_id']);
    $this->assertEquals(7231.05, $contribution['total_amount']);
    // fee_amount is the positive opposite of settled_fee_amount, so that
    // total_amount - fee_amount = net_amount as CiviCRM expects.
    $this->assertEquals(72.31, $contribution['fee_amount']);
    $this->assertEquals(-72.31, $contribution['contribution_settlement.settled_fee_amount']);
    $this->assertSame('overflow', $contribution['contribution_extra.gateway']);
    $this->assertSame('6a7c9a6e7027ca16774e6f76', $contribution['contribution_extra.gateway_txn_id']);
    $this->assertSame('Overflow Disbursements', $contribution['contribution_extra.gateway_account']);
    $this->assertSame($this->batchName, $contribution['contribution_settlement.settlement_batch_reference']);
    $this->assertSame('TSLA', $contribution['Stock_Information.Stock Ticker']);
    $this->assertEquals(1, $contribution['Stock_Information.Stock Quantity']);
    // Stock Value is the net (post-fee) value, not the gross.
    $this->assertEquals(7158.74, $contribution['Stock_Information.Stock Value']);
    $this->assertSame('Major Gifts - CC104', $contribution['Gift_Data.Fund']);
    $this->assertEquals(1, $contribution['Gift_Data.is_major_gift']);
    // Comes from OfflineGift\Save's parent saveContribution(), via SourceFields.
    $this->assertNotEmpty($contribution['contribution_extra.source_name']);

    // It should run again without error, and without creating a duplicate.
    $this->runAuditor($this->file);
    $count = Contribution::get(FALSE)
      ->addWhere('contribution_settlement.settlement_batch_reference', '=', $this->batchName)
      ->selectRowCount()
      ->execute()->count();
    $this->assertEquals(1, $count);
  }

  /**
   * A deposit fetched under the Endowment account should be recorded as an
   * Endowment Gift, not a plain Stock gift - the payment_instrument stays
   * 'Stock' either way since that describes how the gift was made, not
   * which fund it belongs to.
   */
  public function testEndowmentAccountUsesEndowmentGiftFinancialType(): void {
    $this->runAuditBatch('', $this->endowmentFile);

    $contribution = Contribution::get(FALSE)
      ->setSelect(['*', 'payment_instrument_id:name', 'financial_type_id:name', 'contribution_extra.*', 'Gift_Data.Fund'])
      ->addWhere('contribution_settlement.settlement_batch_reference', '=', $this->endowmentBatchName)
      ->execute()->single();

    $this->assertSame('Stock', $contribution['payment_instrument_id:name']);
    $this->assertSame('Endowment Gift', $contribution['financial_type_id:name']);
    // gateway_account is a pass-through of the audit row's own value.
    $this->assertSame('Endowment', $contribution['contribution_extra.gateway_account']);
    $this->assertSame('Endowment Fund', $contribution['Gift_Data.Fund']);
  }

  /**
   * Overflow DAF contributions should route to DAFGift, not StockGift.
   *
   * We don't yet have a donor_advised_fund_name equivalent from Overflow's
   * API, so this can't soft-credit a sponsoring organization - it lands
   * against the individual donor directly, same as a DAF gift with no known
   * fund name would via Chariot.
   */
  public function testDafGiftIsRoutedToDafGift(): void {
    $this->runAuditBatch('', $this->dafFile);

    $contribution = Contribution::get(FALSE)
      ->setSelect([
        '*',
        'contact_id.display_name',
        'payment_instrument_id:name',
        'financial_type_id:name',
        'contribution_extra.*',
        'contribution_settlement.*',
        'Gift_Data.Campaign',
        'Gift_Data.Fund',
        'Gift_Information.import_batch_number',
      ])
      ->addWhere('contribution_settlement.settlement_batch_reference', '=', $this->dafBatchName)
      ->execute()->single();

    $this->assertSame('Bart Daf', $contribution['contact_id.display_name']);
    $this->assertSame('DAFpay', $contribution['payment_instrument_id:name']);
    $this->assertSame('Cash', $contribution['financial_type_id:name']);
    $this->assertSame('Donor Advised Fund', $contribution['Gift_Data.Campaign']);
    $this->assertSame('Major Gifts - CC104', $contribution['Gift_Data.Fund']);
    $this->assertSame('OVERFLOW 6a7c9a6e7027ca16774e6faf', $contribution['trxn_id']);
    $this->assertEquals(50, $contribution['total_amount']);
    $this->assertSame('overflow', $contribution['contribution_extra.gateway']);
    $this->assertSame('Foundation', $contribution['contribution_extra.gateway_account']);
    // import_batch_number is a Chariot-only concept (manual batch tracking).
    $this->assertEmpty($contribution['Gift_Information.import_batch_number']);

    $softCredits = ContributionSoft::get(FALSE)
      ->addWhere('contribution_id', '=', $contribution['id'])
      ->execute();
    $this->assertCount(0, $softCredits);
  }

}

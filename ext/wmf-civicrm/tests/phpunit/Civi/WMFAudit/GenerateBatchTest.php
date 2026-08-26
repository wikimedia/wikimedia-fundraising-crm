<?php

namespace Civi\WMFAudit;

use Civi\Api4\Batch;
use Civi\Api4\Generic\Result;
use Civi\Api4\WMFAudit;
use Civi\FinanceIntegration\Connection;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use League\Csv\Reader;

/**
 * @group Adyen
 * @group WmfAudit
 */
class GenerateBatchTest extends BaseAuditTestCase {
  protected string $gateway = '';

  /**
   * @var \GuzzleHttp\Client
   */
  private Client $mockApiClient;

  private array $container = [];

  private array $webResponses = [];

  public function tearDown():void {
    Connection::resetTestClient();
    Connection::resetTestXmlClient();
    Batch::delete(FALSE)
      ->addWhere('name', 'LIKE', 'adyen_33%')
      ->execute();
    Batch::delete(FALSE)
      ->addWhere('name', 'LIKE', 'dlocal_337%')
      ->execute();
    Batch::delete(FALSE)
      ->addWhere('name', 'LIKE', 'chariot_339%')
      ->execute();
    Batch::delete(FALSE)
      ->addWhere('name', 'LIKE', 'adyen_338%')
      ->execute();
    Batch::delete(FALSE)
      ->addWhere('name', 'LIKE', 'stripemg_340%')
      ->execute();
    parent::tearDown();
  }

  /**
   * getBatches() used to only fetch total_verified/validated batches, so a
   * needs_attention batch could never be picked up for re-validation unless
   * a fresh audit run first promoted it back to total_verified. It should
   * also be picked up directly once it has evidence of a successful total
   * verification (batch_data.last_successful_total_verification_date) -
   * i.e. it was previously confirmed correct, not just newly created.
   */
  public function testNeedsAttentionBatchWithSuccessfulVerificationIsPickedUp(): void {
    $prefix = 'adyen_338';
    $batchName = "{$prefix}_USD";
    $currency = 'USD';
    $settlementDate = '2026-01-26';

    $this->createQueueContribution([
      'Gift_Data.Channel' => 'Mobile Banner',
      'Gift_Data.Fund' => 'Unrestricted',
      'Gift_Data.is_major_gift' => 0,
      'contribution_settlement.settlement_batch_reference' => $batchName,
      'contribution_settlement.settled_donation_amount' => 10.00,
      'contribution_settlement.settlement_currency' => $currency,
      'contribution_settlement.settlement_date' => $settlementDate,
    ]);
    $this->createTestEntity('Batch', [
      'name' => $batchName,
      'mode_id:name' => 'Automatic Batch',
      'status_id:name' => 'needs_attention',
      'item_count' => 1,
      'batch_data.settlement_currency' => $currency,
      'batch_data.settlement_date' => $settlementDate,
      'batch_data.settled_donation_amount' => 10.00,
      'batch_data.settled_reversal_amount' => 0,
      'batch_data.settled_fee_amount' => 0,
      'batch_data.settled_net_amount' => 10.00,
      'batch_data.last_successful_total_verification_date' => date('Y-m-d H:i:s'),
    ], $batchName);

    $result = WMFAudit::generateBatch(FALSE)
      ->setBatchPrefix($prefix)
      ->setIsDryRun(TRUE)
      ->setIsOutputRows(TRUE)
      ->execute();

    $this->assertNotEmpty($result, 'A needs_attention batch with a successful verification date should be picked up');
    $this->assertEquals($batchName, $result[0]['batch']['name']);
  }

  /**
   * Companion to the above - a needs_attention batch that has never had a
   * successful total verification (last_successful_total_verification_date
   * is empty) should not be swept up for re-validation.
   */
  public function testNeedsAttentionBatchWithoutSuccessfulVerificationIsNotPickedUp(): void {
    $prefix = 'adyen_338';
    $batchName = "{$prefix}_USD";
    $currency = 'USD';
    $settlementDate = '2026-01-26';

    $this->createQueueContribution([
      'Gift_Data.Channel' => 'Mobile Banner',
      'Gift_Data.Fund' => 'Unrestricted',
      'Gift_Data.is_major_gift' => 0,
      'contribution_settlement.settlement_batch_reference' => $batchName,
      'contribution_settlement.settled_donation_amount' => 10.00,
      'contribution_settlement.settlement_currency' => $currency,
      'contribution_settlement.settlement_date' => $settlementDate,
    ]);
    $this->createTestEntity('Batch', [
      'name' => $batchName,
      'mode_id:name' => 'Automatic Batch',
      'status_id:name' => 'needs_attention',
      'item_count' => 1,
      'batch_data.settlement_currency' => $currency,
      'batch_data.settlement_date' => $settlementDate,
      'batch_data.settled_donation_amount' => 10.00,
      'batch_data.settled_reversal_amount' => 0,
      'batch_data.settled_fee_amount' => 0,
      'batch_data.settled_net_amount' => 10.00,
    ], $batchName);

    $result = WMFAudit::generateBatch(FALSE)
      ->setBatchPrefix($prefix)
      ->setIsDryRun(TRUE)
      ->setIsOutputRows(TRUE)
      ->execute();

    $this->assertCount(0, $result, 'A needs_attention batch with no successful verification date should not be picked up');
  }

  public function testChannelsWithSameGlCodeAreGroupedIntoSingleJournalRow(): void {
    $prefix = 'adyen_333';
    $batchName = "{$prefix}_USD";
    $currency = 'USD';
    $settlementDate = '2026-01-20';

    // Mobile Banner / Desktop Banner / Other Banner => all map to ACCT_NO 43481 in getAccountClause().
    $this->createQueueContribution([
      'Gift_Data.Channel' => 'Mobile Banner',
      'Gift_Data.Fund' => 'Unrestricted',
      'Gift_Data.is_major_gift' => 0,
      'contribution_settlement.settlement_batch_reference' => $batchName,
      'contribution_settlement.settled_donation_amount' => 10.00,
      'contribution_settlement.settlement_currency' => $currency,
      'contribution_settlement.settlement_date' => $settlementDate,
    ]);
    $this->createQueueContribution([
      'Gift_Data.Channel' => 'Desktop Banner',
      'Gift_Data.Fund' => 'Unrestricted',
      'Gift_Data.is_major_gift' => 0,
      'contribution_settlement.settlement_batch_reference' => $batchName,
      'contribution_settlement.settled_donation_amount' => 20.00,
      'contribution_settlement.settlement_currency' => $currency,
      'contribution_settlement.settlement_date' => $settlementDate,
    ]);
    $this->createQueueContribution([
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
    $this->assertEquals('V01670', $row['GLENTRY_VENDORID']);
    $this->assertSame(3, $this->memoCount($row), 'Expected MEMO count to equal number of contributions grouped');
  }

  /**
   * Test that an endowment journal is generated.
   *
   * @return void
   */
  public function testEndowmentJournal(): void {
    $prefix = 'adyen_333';
    $batchName = "{$prefix}_USD";
    $currency = 'USD';
    $settlementDate = '2026-01-20';

    // Mobile Banner / Desktop Banner / Other Banner => all map to ACCT_NO 43481 in getAccountClause().
    $this->createQueueContribution([
      'financial_type_id:name' => 'Endowment Gift',
      'Gift_Data.Channel' => 'Mobile Banner',
      'Gift_Data.Fund' => 'Unrestricted',
      'Gift_Data.is_major_gift' => 0,
      'contribution_settlement.settlement_batch_reference' => $batchName,
      'contribution_settlement.settled_donation_amount' => 10.00,
      'contribution_settlement.settlement_currency' => $currency,
      'contribution_settlement.settlement_date' => $settlementDate,
    ]);
    $this->createQueueContribution([
      'Gift_Data.Channel' => 'Desktop Banner',
      'Gift_Data.Fund' => 'Unrestricted',
      'Gift_Data.is_major_gift' => 0,
      'contribution_settlement.settlement_batch_reference' => $batchName,
      'contribution_settlement.settled_donation_amount' => 20.00,
      'contribution_settlement.settlement_currency' => $currency,
      'contribution_settlement.settlement_date' => $settlementDate,
    ]);
    $this->addBatchWebResponses();
    // Endowment batch from main instance.
    $this->addBatchWebResponses('10.00');
    // Endowment batch to main instance.
    $this->addBatchWebResponses('10.00');

    $this->runGenerateWithGuzzle($batchName, $currency, $settlementDate, 30, 2, $prefix);
    $batch = Batch::get(FALSE)
      ->addSelect('*', 'status_id:name', 'batch_data.*')
      ->addWhere('name', '=', 'adyen_333_USD')
      ->execute()->single();
    $this->assertEquals('Exported', $batch['status_id:name']);
    $this->assertEquals('https://example.org', $batch['batch_data.remote_url_endowment_instance']);
    $this->assertEquals('https://example.org', $batch['batch_data.remote_url_to_endowment']);
    $this->assertEquals('https://example.org', $batch['batch_data.remote_url_main']);
  }

  public function testSameGlCodeDoesNotGroupAcrossDifferentFunds(): void {
    $prefix = 'adyen_335';
    $batchName = "{$prefix}_USD";
    $currency = 'USD';
    $settlementDate = '2026-01-21';

    // Both channels map to 43484, but different Fund => GROUP BY Fund, ACCT_NO, is_major_gift => should become 2 rows.
    $this->createQueueContribution([
      'Gift_Data.Channel' => 'SMS', // 43484
      'Gift_Data.Fund' => 'Unrestricted',
      'Gift_Data.is_major_gift' => 0,
      'contribution_settlement.settlement_batch_reference' => $batchName,
      'contribution_settlement.settled_donation_amount' => 10.00,
      'contribution_settlement.settlement_currency' => $currency,
      'contribution_settlement.settlement_date' => $settlementDate,
    ]);
    $this->createQueueContribution([
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

    $this->createQueueContribution([
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
  private function createQueueContribution(array $spec): void {
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
    if (isset($parts[3]) && is_numeric($parts[3])) {
      return (int) $parts[3];
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

  public function testGenerateBatchFailedRetrieveVendorID() {
    // If the gateway (prefix) is not present in getVendorCodesForGateways(),
    // GenerateBatch::getVendorCode() should throw a CRM_Core_Exception.
    $prefix = 'wronggateway';
    $batchName = "{$prefix}_USD";
    $currency = 'USD';
    $settlementDate = '2026-01-30';

    // Create a minimal batch that will be picked up by the action using the
    // helper from this test case.
    $this->createBatch($batchName, $currency, $settlementDate, 0.0, 0);

    $this->expectException(\CRM_Core_Exception::class);
    $this->expectExceptionMessage('batch vendor ID missing for ' . $prefix);

    // Trigger the generation which should call getVendorCode() and raise.
    WMFAudit::generateBatch(FALSE)
      ->setBatchPrefix($prefix)
      ->setIsDryRun(TRUE)
      ->setIsOutputRows(TRUE)
      ->execute();
  }

  /**
   * Stripe Major Gifts (stripemg) transactions are actually audited, and
   * trxn_id-prefixed, as 'stripe' - see GatewayAccount.gateway and T432813.
   *
   * getGatewayLevelTransactionExcludeClause() used to build its trxn_id LIKE
   * patterns from the raw batch name prefix ('stripemg'), so a gateway-level
   * fee row (trxn_id 'stripe fee ...') was never recognised as such: it fell
   * through into the per-transaction "Donation Fees" bucket instead of the
   * "Invoice Fees" bucket, and its row was silently dropped from the batch's
   * item count, causing a batch that should validate to report a discrepancy.
   *
   * @throws \CRM_Core_Exception
   */
  public function testGatewayLevelFeeIsClassifiedCorrectlyForStripeMg(): void {
    $prefix = 'stripemg_340';
    $batchName = "{$prefix}_USD";
    $currency = 'USD';
    $settlementDate = '2026-01-27';

    $this->createQueueContribution([
      'trxn_id' => 'stripe fee 555',
      'Gift_Data.Channel' => 'Mobile Banner',
      'Gift_Data.Fund' => 'Unrestricted',
      'Gift_Data.is_major_gift' => 0,
      'contribution_settlement.settlement_batch_reference' => $batchName,
      'contribution_settlement.settled_donation_amount' => 10.00,
      'contribution_settlement.settled_fee_amount' => -1.00,
      'contribution_settlement.settlement_currency' => $currency,
      'contribution_settlement.settlement_date' => $settlementDate,
    ]);

    $this->createTestEntity('Batch', [
      'name' => $batchName,
      'mode_id:name' => 'Automatic Batch',
      'status_id:name' => 'total_verified',
      // The donation row and the gateway-level fee row each count as one item.
      'item_count' => 2,
      'batch_data.settlement_currency' => $currency,
      'batch_data.settlement_date' => $settlementDate,
      'batch_data.settled_donation_amount' => 10.00,
      'batch_data.settled_reversal_amount' => 0,
      'batch_data.settled_fee_amount' => -1.00,
      'batch_data.settled_net_amount' => 9.00,
    ], $batchName);

    $result = WMFAudit::generateBatch(FALSE)
      ->setBatchPrefix($prefix)
      ->setIsDryRun(TRUE)
      ->setIsOutputRows(TRUE)
      ->execute();

    $this->assertNotEmpty($result, 'Expected at least one batch result');
    $batchResult = $result[0];
    $this->assertEquals($batchName, $batchResult['batch']['name']);
    $this->assertEquals(0, array_sum($batchResult['validation']), 'Batch should validate once the gateway-level fee is correctly classified: ' . print_r($batchResult['validation'], TRUE));

    $rows = $batchResult['csv_rows'] ?? [];
    $feeRows = array_values(array_filter($rows, fn($r) => str_contains((string) ($r['MEMO'] ?? ''), 'Fees')));
    $this->assertCount(1, $feeRows, 'Expected a single fee row');
    $this->assertStringEndsWith('Invoice Fees', $feeRows[0]['MEMO'], 'Gateway-level stripe fee should be classified as an Invoice Fee, not a Donation Fee');
  }

  /**
   * The generated journal rows' vendor ID should come from the configured
   * GatewayAccount record for the batch's gateway, not a hard-coded array -
   * and different gateways should get their own distinct code.
   *
   * @throws \CRM_Core_Exception
   */
  public function testGeneratedRowsUseVendorCodeFromGatewayAccount(): void {
    $settlementDate = '2026-01-23';

    $adyenBatchName = 'adyen_337_USD';
    $this->createQueueContribution([
      'Gift_Data.Channel' => 'Mobile Banner',
      'Gift_Data.Fund' => 'Unrestricted',
      'Gift_Data.is_major_gift' => 0,
      'contribution_settlement.settlement_batch_reference' => $adyenBatchName,
      'contribution_settlement.settled_donation_amount' => 10.00,
      'contribution_settlement.settlement_currency' => 'USD',
      'contribution_settlement.settlement_date' => $settlementDate,
    ]);
    $adyenResult = $this->runGenerate($adyenBatchName, 'USD', $settlementDate, 10, 1, 'adyen_337');
    $adyenRows = $adyenResult[0]['csv_rows'] ?? [];
    $this->assertNotEmpty($adyenRows);
    // Vendor code from GatewayAccount_adyen in GatewayAccount.mgd.php.
    $this->assertEquals('V01670', $adyenRows[0]['GLENTRY_VENDORID']);

    $dlocalBatchName = 'dlocal_337_USD';
    $this->createQueueContribution([
      'Gift_Data.Channel' => 'Mobile Banner',
      'Gift_Data.Fund' => 'Unrestricted',
      'Gift_Data.is_major_gift' => 0,
      'contribution_settlement.settlement_batch_reference' => $dlocalBatchName,
      'contribution_settlement.settled_donation_amount' => 10.00,
      'contribution_settlement.settlement_currency' => 'USD',
      'contribution_settlement.settlement_date' => $settlementDate,
    ]);
    $dlocalResult = $this->runGenerate($dlocalBatchName, 'USD', $settlementDate, 10, 1, 'dlocal_337');
    $dlocalRows = $dlocalResult[0]['csv_rows'] ?? [];
    $this->assertNotEmpty($dlocalRows);
    // Vendor code from GatewayAccount_dlocal in GatewayAccount.mgd.php.
    $this->assertEquals('V04134', $dlocalRows[0]['GLENTRY_VENDORID']);
  }

  /**
   * The reversal row written to the journal CSV should use chariot's own
   * balancing_account_foundation (10951) from its GatewayAccount record,
   * not the common 11250 default that most other non-dlocal/paypal
   * gateway falls back to.
   *
   * @throws \League\Csv\Exception
   * @throws \League\Csv\UnavailableStream
   */
  public function testGeneratedJournalUsesBalancingAccountFromGatewayAccount(): void {
    $prefix = 'chariot_339';
    $batchName = "{$prefix}_USD";
    $currency = 'USD';
    $settlementDate = '2026-01-24';

    $this->createQueueContribution([
      'Gift_Data.Channel' => 'Mobile Banner',
      'Gift_Data.Fund' => 'Unrestricted',
      'Gift_Data.is_major_gift' => 0,
      'contribution_settlement.settlement_batch_reference' => $batchName,
      'contribution_settlement.settled_donation_amount' => 10.00,
      'contribution_settlement.settlement_currency' => $currency,
      'contribution_settlement.settlement_date' => $settlementDate,
    ]);
    $this->createBatch($batchName, $currency, $settlementDate, 10, 1);
    try {
      $result = WMFAudit::generateBatch(FALSE)
        ->setBatchPrefix($prefix)
        ->setIsDryRun(TRUE)
        ->setIsOutputRows(TRUE)
        ->setIsOutputCsv(TRUE)
        ->execute();
    }
    catch (\CRM_Core_Exception $e) {
      $this->fail($e->getMessage());
    }

    $journalFile = $result[0]['csv']['journal_file']['file'] ?? NULL;
    $this->assertNotEmpty($journalFile, 'Expected a journal csv file to have been written');

    $reader = Reader::from($journalFile);
    $reader->setHeaderOffset(0);
    $balancingRows = array_values(array_filter(iterator_to_array($reader), function ($row) {
      return (string) ($row['ACCT_NO'] ?? '') === '10951';
    }));
    $this->assertNotEmpty($balancingRows, 'Expected a reversal row using chariot\'s own balancing account (10951)');
  }

  /**
   * @param string $batchName
   * @param string $currency
   * @param string $settlementDate
   * @param string $prefix
   *
   * @return \Civi\Api4\Generic\Result
   */
  public function runGenerateWithGuzzle(string $batchName, string $currency, string $settlementDate, float $amount, $itemCount, string $prefix): Result {
    // Batch expected totals must match what GenerateBatch will compute, or it flags discrepancy.
    $this->createBatch($batchName, $currency, $settlementDate, $amount, $itemCount);
    try {
      $this->container = [];
      $history = Middleware::history($this->container);
      $handlerStack = HandlerStack::create(
        new MockHandler($this->webResponses)
      );
      $handlerStack->push($history);
      $this->mockApiClient = new Client([
        'handler' => $handlerStack,
      ]);
      Connection::setTestClient($this->mockApiClient);
      // Also intercept the XML gateway (process_log pushes triggered as a
      // side effect of each successful journal push) - without this, if
      // real FINANCE_OAUTH constants happen to be configured, PushJournal
      // would attempt a live HTTP call to Intacct's XML endpoint. The exact
      // content doesn't matter - PushProcessLog doesn't parse the response.
      Connection::setTestXmlClient(new Client([
        'handler' => HandlerStack::create(new MockHandler(array_fill(0, 10, new Response(200, [], '<response>success</response>')))),
      ]));
      $connection = $this->createMock(Connection::class);
      $connection
        ->method('getApiClient')
        ->willReturn($this->mockApiClient);
      return WMFAudit::generateBatch(FALSE)
        ->setBatchPrefix($prefix)
        ->setIsDryRun(FALSE)
        ->setIsOutputRows(TRUE)
        ->setIsOutputCsv(TRUE)
        ->setOutputMethod('api')
        ->execute();
    }
    catch (\CRM_Core_Exception $e) {
      $this->fail($e->getMessage());
    }
  }

  /**
   * @param array $result
   *
   * @return void
   */
  public function addWebResponse(array $result, $meta = []): void {
    $this->webResponses[] = new Response(200,
      ['Content-Type' => 'application/json'],
      json_encode([
        'ia::result' => $result,
        'ia::meta' => $meta,
      ]
    ));
  }

  /**
   * @param string $total
   *
   * @return void
   */
  public function addBatchWebResponses($total = '30.00'): void {
    // First https call is to check for existing journal - empty result works.
    $this->addWebResponse([]);
    // Second call is posting our new journal - the id is returned.
    $this->addWebResponse(['id' => 123]);
    // Third & fourth are to get the details of the journal to check it is valid and matches.
    $this->addWebResponse([['id' => 123, 'webURL' => 'https://example.org']], ['totalCount' => 1]);
    $this->addWebResponse([
      'txnNumber' => 456,
      'lines' => [
        [
          'id' => '1001',
          'txnType' => 'Debit',
          'txnAmount' => $total,
          'journalEntry.id' => '123456',
          'currency' => ['txnCurrency' => 'USD', 'exchangeRate' => 1],
        ],
        [
          'id' => '1002',
          'txnType' => 'Credit',
          'txnAmount' => $total,
          'journalEntry.id' => '123456',
          'currency' => ['txnCurrency' => 'USD', 'exchangeRate' => 1],
        ],
      ],
    ]);
    // Get existing lines response.
    $this->addWebResponse([
      [
        'id' => '1001',
        'txnType' => 'Debit',
        'txnAmount' => $total,
        'journalEntry.id' => '123456',
        'currency' => ['txnCurrency' => 'USD', 'exchangeRate' => 1],
      ],
      [
        'id' => '1002',
        'txnType' => 'Credit',
        'txnAmount' => $total,
        'journalEntry.id' => '123456',
        'currency' => ['txnCurrency' => 'USD', 'exchangeRate' => 1],
      ],
    ]);
  }

}

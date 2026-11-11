<?php

namespace Civi\Api4\Action\FinanceIntegration;

use Civi\Api4\Batch;
use Civi\Api4\FinanceIntegration;
use Civi\FinanceIntegration\Connection;
use Civi\Test\EntityTrait;
use Civi\Test\HeadlessInterface;
use Civi\WMFEnvironmentTrait;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

/**
 * Covers the Batch fields a single journal push records for itself.
 *
 * @group headless
 */
class PushJournalTest extends TestCase implements HeadlessInterface {

  use WMFEnvironmentTrait;
  use EntityTrait;

  private const BATCH_NAME = 'adyen_349_USD';

  private string $journalFile = '';

  public function setUp(): void {
    $this->setSetting('intacct_allowed_upload_folders', [sys_get_temp_dir()]);
    $this->journalFile = sys_get_temp_dir() . '/' . self::BATCH_NAME . '_push_journal_test.csv';
    file_put_contents($this->journalFile, "LINE_NO,ACCT_NO,CURRENCY,DEBIT,CREDIT,GLDIMFUNDING\n1,43481,USD,0.00,30.00,Unrestricted\n2,11250,USD,30.00,0.00,Unrestricted\n");
    parent::setUp();
  }

  public function tearDown(): void {
    Connection::resetTestClient();
    Connection::resetTestXmlClient();
    if (file_exists($this->journalFile)) {
      unlink($this->journalFile);
    }
    Batch::delete(FALSE)->addWhere('name', '=', self::BATCH_NAME)->execute();
    parent::tearDown();
  }

  /**
   * Given the CiviCRM batch it belongs to, a confirmed journal should write
   * what Intacct told it - remote url/identifier and exchange rate - onto
   * that batch. Not its status, which only the caller can decide once every
   * journal is accounted for, and not the journaled amounts, which the
   * generator works out and records for itself.
   */
  public function testConfirmedJournalRecordsItsOwnBatchFields(): void {
    $this->createBatch();
    $this->mockConfirmedJournal();

    FinanceIntegration::pushJournal(FALSE)
      ->setJournalFile($this->journalFile)
      ->setBatchName(self::BATCH_NAME)
      ->setCiviBatchName(self::BATCH_NAME)
      ->setRemoteDescriptor('foundation')
      ->execute();

    $batch = $this->getBatch();
    $this->assertEquals('https://example.org', $batch['batch_data.remote_url_foundation']);
    $this->assertEquals(456, $batch['batch_data.remote_identifier_foundation']);
    $this->assertEquals(1, $batch['batch_data.exchange_rate']);
    $this->assertEquals('Intacct', $batch['batch_data.exchange_rate_source']);
    $this->assertEmpty($batch['batch_data.amount_journaled_to_endowment'], 'Journaled amounts are the generator\'s to record, not the push\'s');
    $this->assertEmpty($batch['batch_data.amount_journaled_to_foundation']);
    $this->assertEquals('total_verified', $batch['status_id:name'], 'Closing the batch is the caller\'s call, not a single journal push\'s');
  }

  /**
   * The remote descriptor picks which remote_url_x/remote_identifier_x pair
   * the journal lands in - a transfer journal to the endowment instance
   * should not overwrite the foundation journal's fields.
   */
  public function testRemoteDescriptorSelectsTheFieldPair(): void {
    $this->createBatch(['batch_data.remote_url_foundation' => 'https://example.org/foundation']);
    $this->mockConfirmedJournal();

    FinanceIntegration::pushJournal(FALSE)
      ->setJournalFile($this->journalFile)
      ->setBatchName(self::BATCH_NAME . 'e')
      ->setCiviBatchName(self::BATCH_NAME)
      ->setRemoteDescriptor('to_endowment')
      ->execute();

    $batch = $this->getBatch();
    $this->assertEquals('https://example.org', $batch['batch_data.remote_url_to_endowment']);
    $this->assertEquals(456, $batch['batch_data.remote_identifier_to_endowment']);
    $this->assertEquals('https://example.org/foundation', $batch['batch_data.remote_url_foundation']);
  }

  /**
   * A journal pushed without a CiviCRM batch behind it - e.g. by hand -
   * should still be validated against Intacct, just with no batch to update.
   */
  public function testNoCiviBatchNameLeavesTheBatchAlone(): void {
    $this->createBatch();
    $this->mockConfirmedJournal();

    $result = FinanceIntegration::pushJournal(FALSE)
      ->setJournalFile($this->journalFile)
      ->setBatchName(self::BATCH_NAME)
      ->execute();

    $this->assertEquals('Valid Remotely', $result[0]['status']);
    $batch = $this->getBatch();
    $this->assertEmpty($batch['batch_data.remote_url_foundation']);
    $this->assertEmpty($batch['batch_data.exchange_rate_source']);
  }

  private function createBatch(array $values = []): void {
    $this->createTestEntity('Batch', $values + [
      'name' => self::BATCH_NAME,
      'batch_data.settlement_gateway_account_id' => $this->getGatewayAccountID('adyen'),
      'mode_id:name' => 'Automatic Batch',
      'status_id:name' => 'total_verified',
      'item_count' => 1,
      'batch_data.settlement_currency' => 'USD',
      'batch_data.settlement_date' => '2026-02-02',
      'batch_data.settled_donation_amount' => 30.00,
      'batch_data.settled_net_amount' => 30.00,
    ], self::BATCH_NAME);
  }

  private function getBatch(): array {
    return (array) Batch::get(FALSE)
      ->addSelect('*', 'status_id:name', 'batch_data.*')
      ->addWhere('name', '=', self::BATCH_NAME)
      ->execute()->single();
  }

  /**
   * Mock the three calls PushJournal makes when the journal is already in
   * Intacct with totals matching the csv: find it by reference number, fetch
   * its details (web url, txn number, exchange rate) and fetch its lines.
   */
  private function mockConfirmedJournal(): void {
    $lines = [
      ['id' => '1001', 'txnType' => 'Debit', 'txnAmount' => '30.00', 'currency' => ['txnCurrency' => 'USD', 'exchangeRate' => 1]],
      ['id' => '1002', 'txnType' => 'Credit', 'txnAmount' => '30.00', 'currency' => ['txnCurrency' => 'USD', 'exchangeRate' => 1]],
    ];
    Connection::setTestClient(new Client([
      'handler' => HandlerStack::create(new MockHandler([
        $this->jsonResponse([['id' => 123, 'webURL' => 'https://example.org']], ['totalCount' => 1]),
        $this->jsonResponse(['txnNumber' => 456, 'lines' => $lines]),
        $this->jsonResponse($lines),
      ])),
    ]));
  }

  private function jsonResponse(array $result, array $meta = []): Response {
    return new Response(200, ['Content-Type' => 'application/json'], json_encode(['ia::result' => $result, 'ia::meta' => $meta]));
  }

}

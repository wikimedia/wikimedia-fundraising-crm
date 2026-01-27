<?php

namespace phpunit\Civi\WMFAudit;

use Civi\Api4\Batch;
use Civi\Api4\Contribution;
use Civi\Api4\ContributionRecur;
use Civi\Api4\ContributionTracking;
use Civi\Api4\GrantTransaction;
use Civi\Api4\TransactionLog;
use Civi\WMFAudit\BaseAuditTestCase;
use League\Csv\Exception;
use League\Csv\Reader;

/**
 * @group Adyen
 * @group WmfAudit
 */
class PaypalAuditTest extends BaseAuditTestCase {
  protected string $gateway = 'paypal';

  public function setUp(): void {
    parent::setUp();
  }

  public function tearDown(): void {
    Batch::delete(FALSE)
      ->addWhere('name', 'LIKE', 'paypal_202601%')
      ->execute();
    $transactions = [
      '1V551844CE5526421',
      '5W55',
    ];
    TransactionLog::delete(FALSE)
      ->addWhere('gateway_txn_id', 'IN', $transactions)->execute();
    Contribution::delete(FALSE)
      ->addWhere('contribution_extra.gateway_txn_id', 'IN', $transactions)->execute();
    ContributionRecur::delete(FALSE)
      ->addWhere('trxn_id', 'IN', ['I-CRT'])->execute();
    parent::tearDown();
  }

  /**
   * Test basic trr donation file.
   * @throws \CRM_Core_Exception
   */
  public function testTRRFile(): void {
    $this->runAuditBatch('trr_file', 'trr_paypal_express_donation.csv');
    $contribution = Contribution::get(FALSE)
      ->addSelect('contribution_extra.*')
      ->addWhere('contribution_extra.gateway', '=', 'paypal_ec')
      ->addWhere('contribution_extra.gateway_txn_id', '=', '1V551844CE5526421')
      ->execute()->single();
    $this->assertEquals('JPY', $contribution['contribution_extra.original_currency']);
  }

  /**
   * Test that a paypal_express donation can be matched with an existing donation where paypal is the gateway.
   *
   * First - we check that when it is incoming with a TransactionLog that sets the gateway
   * to
   * @throws \CRM_Core_Exception
   */
  public function testTRRFileWithMismatchedGateway(): void {
    $fileName = 'trr_paypal_express_donation.csv';
    $this->prepareForAuditProcessing('trr_file', $fileName);
    $transactionLog = TransactionLog::get(FALSE)
      ->addWhere('gateway_txn_id', '=', '1V551844CE5526421')->execute()->single();
    TransactionLog::update(FALSE)
      ->addValue('gateway', 'paypal')
      ->addValue('message', ['gateway' => 'paypal'] + $transactionLog['message'])
      ->addWhere('gateway_txn_id', '=', '1V551844CE5526421')
      ->execute();
    $this->runAuditor($fileName);
    $this->processDonationsQueue();

    $contribution = Contribution::get(FALSE)
      ->addSelect('contribution_extra.*')
      ->addWhere('contribution_extra.gateway', '=', 'paypal')
      ->addWhere('contribution_extra.gateway_txn_id', '=', '1V551844CE5526421')
      ->execute()->single();
    $this->assertEquals('JPY', $contribution['contribution_extra.original_currency']);

    // Now run the batch again and check it does not queue anything because it should decide nothing
    // is missing.
    $this->runAuditor($fileName);
    $this->assertQueueEmpty('donations');
  }

  /**
   * Test basic trr donation file.
   * @throws \CRM_Core_Exception
   */
  public function testTRRFileRecurringPayment(): void {
    $contactID = $this->createTestEntity('Contact', [
      'contact_type' => 'Individual',
      'first_name' => 'John',
      'last_name' => 'Mouse',
    ], 'john')['id'];
    $this->createTestEntity('ContributionRecur', [
      'processor_id' => 'I-CRT',
      'trxn_id' => 'I-CRT',
      'amount' => 10,
      'contact_id' => $contactID,
    ], 'recur');
    $this->createTestEntity('Contribution', [
      'contribution_recur_id' => $this->ids['ContributionRecur']['recur'],
      'contact_id' => $contactID,
      'total_amount' => 10,
      'financial_type_id:name' => 'Recurring Gift',
      'contribution_extra.channel' => 'Email',
    ]);
    $this->runAuditBatch('trr_recur_payment', 'TRR-20260125.01.008.CSV');
    $contribution = Contribution::get(FALSE)
      ->addSelect('contribution_extra.*', 'contribution_recur_id', 'Gift_Data.*', 'financial_type_id:name')
      ->addWhere('contribution_extra.gateway', '=', 'paypal_ec')
      ->addWhere('contribution_extra.gateway_txn_id', '=', '5W55')
      ->execute()->single();
    $this->assertEquals('Recurring Gift - Cash', $contribution['financial_type_id:name']);
    $this->assertEquals('Recurring Gift', $contribution['Gift_Data.Channel']);
    $this->assertEquals($this->ids['ContributionRecur']['recur'], $contribution['contribution_recur_id']);
  }

  public function testSTLFile(): void {
    $this->runAuditBatch('stl_file', 'STL-20260106.01.009.csv', '20260106');
    $contribution = Contribution::get(FALSE)
      ->addSelect('contribution_extra.*')
      ->addWhere('contribution_extra.gateway', '=', 'paypal')
      ->addWhere('contribution_extra.gateway_txn_id', '=', '8MV64')
      ->execute()->single();
    $this->assertEquals('BRL', $contribution['contribution_extra.original_currency']);
  }

  public function testPaypalGrants() {
    $this->createTestEntity('Contribution', [
      'contact_id' => $this->createIndividual(),
      'total_amount' => 500,
      'financial_type_id:name' => 'Cash',
      'contribution_extra.gateway' => 'Paypal DAF',
    ], 'grant');
    $this->createTestEntity('Contact', ['contact_type' => 'Organization', 'organization_name' => 'Acme Endowment Program'], 'acme');
    $this->createTestEntity('ContributionSoft', [
      'contribution_id' => $this->ids['Contribution']['grant'],
      'soft_credit_type_id:name' => 'Banking Institution',
      'contact_id' => $this->ids['Contact']['acme'],
      'amount' => 500,
    ]);
    $this->runAuditBatch('trr_grant', 'trr_grant.csv', '20260106');
    $grantTransaction = GrantTransaction::get(FALSE)
      ->addWhere('gateway', '=', 'paypal DAF')
      ->addWhere('gateway_txn_id', '=', '1V05')
      ->execute()->single();

    $this->assertEquals('Acme Endowment Program', $grantTransaction['grant_provider']);
  }

  /**
   * @param string $directory
   * @param string $fileName
   * @return Reader
   */
  public function getRows(string $directory, string $fileName): array {
    $this->setAuditDirectory($directory);
    // First let's have a process to create some TransactionLog entries.
    $file = $this->auditFileBaseDirectory . DIRECTORY_SEPARATOR . $directory . DIRECTORY_SEPARATOR . $this->gateway . DIRECTORY_SEPARATOR . 'incoming' . DIRECTORY_SEPARATOR . $fileName;
    $columnHeaders = [];
    $rows = [];
    try {
      $csv = Reader::createFromPath($file, 'r');
      $file = fopen( $file, 'r' );
      while ( ( $line = fgetcsv( $file, 0 ) ) !== false ) {
        // skip empty lines
        if ($line === [NULL]) {
          continue;
        }

        $recordType = $line[0] ?? '';
        // Remove UTF-8 BOM if present
        $recordType = preg_replace('/^\xEF\xBB\xBF/', '', $recordType);
        if ($recordType === 'CH') {
          $columnHeaders = $line;
        }
        if ($recordType === 'SB') {
          $rows[] = array_combine($columnHeaders, $line);
        }

      }
    } catch (Exception $e) {
      $this->fail('Failed to read csv' . $file . ': ' . $e->getMessage());
    }
    return $rows;
  }

  public function createTransactionLog(array $row): void {
    $orderID = $row['Invoice ID'];
    $trackingID = explode('.', $orderID)[0];
    $isGravy = !is_numeric($trackingID);
    if ($isGravy) {
      $trackingID = 1 + ((int) \CRM_Core_DAO::singleValueQuery('SELECT MAX(id) FROM civicrm_contribution_tracking'));
    }
    $utmSource = "B2526_082914_esLA_m_p1_lg_twn_twin1_optIn0.no-LP.apple_amex";
    $this->ids['ContributionTracking'][] = ContributionTracking::save(FALSE)
      ->addRecord([
        'id' => $trackingID,
        'utm_source' => $utmSource,
      ])
      ->execute()->first()['id'];
    $isExpress = ($row['Payment Source'] ?? '' === 'Express Checkout') || str_starts_with($row['PayPal Reference ID'] ?? '', 'I');
    $gateway = $isGravy ? 'gravy' : ($isExpress ? 'paypal_ec' : 'paypal');
    $gatewayTxnID = $row['Transaction ID'];
    $this->createTestEntity('TransactionLog', [
      'date' => $row['Transaction Initiation Date'],
      'gateway' => $gateway,
      'gateway_account' => 'WikimediaDonations',
      'order_id' => $trackingID . '.1',
      'gateway_txn_id' => $gatewayTxnID,
      'message' => [
        "gateway_txn_id" => $gatewayTxnID,
        "response" => FALSE,
        "gateway_account" => "WikimediaDonations",
        "fee" => 0,
        "gross" => $row['Gross Transaction Amount'],
        "backend_processor" => $isGravy ? $this->gateway : NULL,
        "backend_processor_txn_id" => $isGravy ? $row['Transaction ID'] : NULL,
        "contribution_tracking_id" => $trackingID,
        "payment_orchestrator_reconciliation_id" => $isGravy ? $row['Transaction ID'] : NULL,
        "currency" => $row['Gross Transaction Currency'],
        "order_id" => $trackingID . '.1',
        "payment_method" => "apple",
        "payment_submethod" => "amex",
        "email" => $gatewayTxnID . "@wikimedia.org",
        "first_name" => $gatewayTxnID,
        "gateway" => $gateway,
        "last_name" => "Mouse",
        "user_ip" => "169.255.255.255",
        "utm_campaign" => "WMF_FR_C2526_esLA_m_0805",
        "utm_medium" => "sitenotice",
        "utm_source" => $utmSource,
        "date" => strtotime($row['Transaction Initiation Date']),
      ],
    ], $gatewayTxnID);
  }

}

<?php

namespace Civi\WMFAudit;

use Civi\Api4\Contribution;
use Civi\Api4\ContributionTracking;
use League\Csv\Exception;
use League\Csv\Reader;
use SmashPig\Core\Helpers\Base62Helper;

/**
 * @group Dlocal
 * @group WmfAudit
 * @group DlocalAudit
 */
class DlocalAuditTest extends BaseAuditTestCase {

  protected string $gateway = 'dlocal';

  static protected $loglines;

  public function setUp(): void {
    parent::setUp();
    // First we need to set an exchange rate for a sickeningly specific time
    $this->setExchangeRates(1434488406, ['BRL' => 3.24]);
    $this->setExchangeRates(1434488406, ['USD' => 1]);
    $msg = [
      'contribution_tracking_id' => 24761,
      'currency' => 'BRL',
      'date' => 1434488406,
      'email' => 'mouse@wikimedia.org',
      'gateway' => 'DLOCAL',
      'gateway_txn_id' => '5138333',
      'gross' => 5.00,
      'payment_method' => 'cc',
      'payment_submethod' => 'mc',
    ];
    $this->processMessage($msg, 'Donation', 'test');
  }

  public function auditTestProvider(): array {
    return [
      'donation' => [
        __DIR__ . '/data/Dlocal/donation/',
        [
          'donations' => [
            [
              'contribution_tracking_id' => '26683111',
              'country' => 'BR',
              'currency' => 'BRL',
              'date' => 1434477552,
              'email' => 'nonchalant@gmail.com',
              'first_name' => 'Test',
              'gateway' => 'dlocal',
              'gateway_account' => 'default',
              'gateway_txn_id' => '5258111',
              'gross' => '5.00',
              'invoice_id' => '26683111.0',
              'language' => 'en',
              'last_name' => 'Mouse',
              'order_id' => '26683111.0',
              'payment_method' => 'cc',
              'payment_submethod' => 'mc',
              'recurring' => '',
              'settled_currency' => 'USD',
              'settled_date' => 1434477632,
              'settled_fee_amount' => -0.03,
              'settled_gross' => '1.50',
              'user_ip' => '1.2.3.4',
              'utm_campaign' => 'C13_en.wikipedia.org',
              'utm_medium' => 'sidebar',
              'utm_source' => '..cc',
              'tracking_date' => '2015-06-16 17:59:12',
              'audit_file_gateway' => 'dlocal',
              'settled_total_amount' => '1.50',
              'settled_net_amount' => 1.47,
              'original_total_amount' => '5.00',
              'exchange_rate' => 0.3,
            ],
          ],
        ],
        [],
      ],
      'bt' =>[
        __DIR__ . '/data/Dlocal/bt/',
        [
          'donations' => [
            [
              'contribution_tracking_id' => '24761',
              'country' => 'BR',
              'currency' => 'BRL',
              'date' => 1434506370,
              'email' => 'jimmy@bankster.com',
              'first_name' => 'Jimmy',
              'gateway' => 'dlocal',
              'gateway_account' => 'default',
              'gateway_txn_id' => '5258777',
              'gross' => '4.00',
              'invoice_id' => '24761.1',
              'language' => 'en',
              'last_name' => 'Mouse',
              'order_id' => '24761.1',
              'payment_method' => 'bt',
              'payment_submethod' => 'bradesco',
              'recurring' => '',
              'settled_currency' => 'USD',
              'settled_date' => 1434506459,
              'settled_fee_amount' => -0.03,
              'settled_gross' => '1.20',
              'user_ip' => '8.8.8.8',
              'utm_campaign' => 'C13_en.wikipedia.org',
              'utm_medium' => 'sidebar',
              'utm_source' => '..bt',
              'audit_file_gateway' => 'dlocal',
              'settled_total_amount' => '1.20',
              'settled_net_amount' => 1.17,
              'original_total_amount' => '4.00',
              'exchange_rate' => 0.3,
              'tracking_date' => '2015-06-17 01:59:30'
            ],
          ],
        ],
        [],
      ],
      'refund' => [
        __DIR__ . '/data/Dlocal/refund/',
        [
          'refund' => [
            [
              'date' => 1434488406,
              'gateway' => 'dlocal',
              'gateway_parent_id' => '5138333',
              'gateway_refund_id' => '33333',
              'gross' => '5.00',
              'gross_currency' => 'BRL',
              'type' => 'refund',
              'settlement_batch_reference' => NULL,
              'settled_total_amount' => NULL,
              'settled_fee_amount' => NULL,
              'settled_net_amount' => NULL,
              'settled_currency' => 'BRL',
              'original_currency' => NULL,
              'settled_date' => NULL,
              'original_net_amount' => NULL,
              'original_fee_amount' => NULL,
              'original_total_amount' => NULL,
            ],
          ],
        ],
        [],
      ],
    ];
  }

  /**
   * @dataProvider auditTestProvider
   */
  public function testParseFiles($path, $expectedMessages, $expectedLoglines) {
    $this->setSetting('wmf_audit_directory_audit', $path);

    $this->runAuditor();

    $this->assertMessages($expectedMessages);
    $this->assertLoglinesPresent($expectedLoglines);
  }

  /**
   */
  public function testProcessRefundSettlement() {
    $this->runAuditBatch('settlement_refund', 'Wikimedia_cross_border_report_20260207_083659.csv');
    // The refund won't get picked up until the second run.
    $this->runAuditBatch('settlement_refund', 'Wikimedia_cross_border_report_20260207_083659.csv');

    $contribution = Contribution::get(FALSE)
      ->addWhere('invoice_id', '=', 2293.1)
      ->addSelect('contribution_status_id:name')
      ->addSelect('contribution_settlement.*')
      ->addSelect('contribution_extra.*')
      ->execute()->single();
    $this->assertEquals('T-648-x1kn16ut', $contribution['contribution_extra.gateway_txn_id']);
    $this->assertEquals('dlocal', $contribution['contribution_extra.gateway']);
    $this->assertEquals('Refunded', $contribution['contribution_status_id:name']);
    $this->assertEquals('dlocal_20260206_USD', $contribution['contribution_settlement.settlement_batch_reference']);
    $this->assertEquals('dlocal_20260206_USD', $contribution['contribution_settlement.settlement_batch_reversal_reference']);
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
    try {
      $csv = Reader::from($file, 'r');
    } catch (Exception $e) {
      $this->fail('Failed to read csv' . $file . ': ' . $e->getMessage());
    }
    $rows = [];
    foreach ($csv as $row) {
      $rowType = $row[0];
      if ($rowType === 'HEADER' || ($rowType === 'ROW_TYPE' && $row[1] === 'SETTLEMENT_CURRENCY')) {
        continue;
      }
      elseif ($rowType === 'ROW_TYPE') {
        $columns = array_values($row);
        continue;
      }
      elseif (!empty($columns)) {
        $rows[] = array_combine($columns, $row);
      }

    }
    return $rows;
  }

  public function createTransactionLog(array $row): void {
    if (in_array($row['ROW_TYPE'], ['ADJUSTMENT', 'HEADER', 'ROW_TYPE'], TRUE)) {
      return;
    }
    $orderID = $row['TRANSACTION_ID'];
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
    $gateway = $isGravy ? 'gravy' : $this->gateway;
    $gatewayTxnID = $gateway === $this->gateway ? $row['DLOCAL_TRANSACTION_ID'] : Base62Helper::toUuid($row['TRANSACTION_ID']);
    if (!$isGravy && $row['ROW_TYPE'] === 'REFUND') {
      // Make it un-findable cos there is no useful linkage. We don't have the original at all here.
      $gatewayTxnID = $gatewayTxnID . 'cant touch this';
    }
    $this->createTestEntity('TransactionLog', [
      'date' => $row['CREATION_DATE'],
      'gateway' => $gateway,
      'gateway_account' => 'WikimediaDonations',
      'order_id' => $trackingID . '.1',
      'gateway_txn_id' => $gatewayTxnID,
      'message' => [
        "gateway_txn_id" => $gatewayTxnID,
        "response" => FALSE,
        "gateway_account" => "WikimediaDonations",
        "fee" => 0,
        "gross" => $row['LOCAL_AMOUNT'],
        "backend_processor" => $isGravy ? "dlocal" : NULL,
        "backend_processor_txn_id" => $isGravy ? $row['DLOCAL_TRANSACTION_ID'] : NULL,
        "contribution_tracking_id" => $trackingID,
        "payment_orchestrator_reconciliation_id" => $isGravy ? $row['TRANSACTION_ID'] : NULL,
        "currency" => $row['LOCAL_CURRENCY'],
        "order_id" => $trackingID . '.1',
        "payment_method" => "apple",
        "payment_submethod" => "amex",
        "email" => $gatewayTxnID . "@wikimedia.org",
        "first_name" => $gatewayTxnID,
        "gateway" => $isGravy ? 'gravy' : 'dlocal',
        "last_name" => "Mouse",
        "user_ip" => "169.255.255.255",
        "utm_campaign" => "WMF_FR_C2526_esLA_m_0805",
        "utm_medium" => "sitenotice",
        "utm_source" => $utmSource,
        "date" => strtotime($row['CREATION_DATE']),
      ]
    ], $gatewayTxnID);
  }

  protected function assertLoglinesPresent($expectedLines) {
    $notFound = [];

    foreach ($expectedLines as $expectedEntry) {
      foreach (self::$loglines as $entry) {
        if ($entry['type'] === $expectedEntry['type']
          && $entry['message'] === $expectedEntry['message']) {
          // Skip to next expected line.
          continue 2;
        }
      }
      // Not found.
      $notFound[] = $expectedEntry;
    }
    if ($notFound) {
      $this->fail("Did not see these log lines, " . json_encode($notFound));
    }
  }

}

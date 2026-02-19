<?php

namespace phpunit\Civi\WMFAudit;

use Civi\Api4\Contribution;
use Civi\Api4\ContributionTracking;
use Civi\WMFAudit\BaseAuditTestCase;
use League\Csv\Exception;
use League\Csv\Reader;
use SmashPig\Core\Helpers\Base62Helper;
use SmashPig\Core\SequenceGenerators\Factory;

/**
 * @group Trustly
 * @group WmfAudit
 * @group TrustlyAudit
 */
class TrustlyAuditTest extends BaseAuditTestCase {

  protected string $gateway = 'trustly';

  static protected $loglines;

  public function testFUNFile(): void {
    // Create donation affected by chargeback.
    $this->createTestEntity('Contribution', [
      'contact_id' => $this->createIndividual(),
      'total_amount' => 26,
      'receive_date' => 'December 27th, 2025 1:44 PM',
      'contribution_extra.gateway' => 'gravy',
      'financial_type_id:name' => 'Cash',
      'contribution_extra.gateway_txn_id' => '1a16a190-a70b-44ab-9858-76ae7b7a6642',
      'contribution_extra.backend_processor' => 'trustly',
      'contribution_extra.backend_processor_txn_id' => '8063154000',
      'contribution_extra.payment_orchestrator_reconciliation_id' => 'nE8rUqc0xFSXUPDJQY654',
      'invoice_number' => '12345.1',
    ]);

    // Create donation affected by refund.
    $this->createTestEntity('Contribution', [
      'contact_id' => $this->createIndividual(),
      'total_amount' => 3.10,
      'receive_date' => 'February 7th, 2026 10:33 AM',
      'contribution_extra.gateway' => 'gravy',
      'financial_type_id:name' => 'Cash',
      'contribution_extra.gateway_txn_id' => ' 	070c47b8-3b8e-48dc-8585-10c4a4020728 ',
      'contribution_extra.backend_processor' => 'trustly',
      'contribution_extra.backend_processor_txn_id' => '8090501261',
      'contribution_extra.payment_orchestrator_reconciliation_id' => 'DIYcExJDXRRFjjf4NLhEG',
      'invoice_number' => '12346.1',
    ]);

    // Run it twice so the one that is refunded gets a chance to 'take'
    $this->runAuditBatch('fun_file', 'P11KFUN-3618-20260201120000-20260202120000-0001of0001.csv');
    $this->runAuditBatch('fun_file', 'P11KFUN-3618-20260201120000-20260202120000-0001of0001.csv', '999');
    $contribution = Contribution::get(FALSE)
      ->addSelect('contribution_extra.*')
      ->addWhere('contribution_extra.gateway', '=', 'gravy')
      ->addWhere('contribution_extra.gateway_txn_id', '=', '0e5d047c-e2ed-4bec-a3fc-156fccf64e13')
      ->execute()->single();
    $this->assertEquals('USD', $contribution['contribution_extra.original_currency']);
  }

  public function createTransactionLog(array $row): void {
    $utmSource = "B2526_082914_esLA_m_p1_lg_twn_twin1_optIn0.no-LP.apple_amex";
    $trackingID = $this->createContributionTracking([
      'utm_source' => $utmSource,
    ])['id'];
    $gateway = 'gravy';
    $gatewayTxnID = Base62Helper::toUuid($row['payment_processor_reconciliation_id']);
    $this->createTestEntity('TransactionLog', [
      'date' => $row['date'],
      'gateway' => $gateway,
      'gateway_account' => 'WikimediaDonations',
      'order_id' => $trackingID . '.1',
      'gateway_txn_id' => $gatewayTxnID,
      'message' => [
        "gateway_txn_id" => $gatewayTxnID,
        "response" => FALSE,
        "gateway_account" => "WikimediaDonations",
        "fee" => 0,
        "gross" => $row['gross'],
        "backend_processor" => 'dlocal',
        "backend_processor_txn_id" => $row['dlocal_id'],
        "contribution_tracking_id" => $trackingID,
        "payment_orchestrator_reconciliation_id" => $row['payment_processor_reconciliation_id'],
        "currency" => 'USD',
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
        "date" => strtotime($row['date']),
      ],
    ], $gatewayTxnID);
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
      if ($row[0] === 'H' || $row[0] === 'L' || $row[0] === 'F') {
        continue;
      }
      if ($row[15] <= 0) {
        continue;
      }
      $rows[] = [
        'payment_processor_reconciliation_id' => $row[20],
        'gross' => $row[15],
        'date' => $row[13],
        'dlocal_id' => $row[1],
      ];
    }
    return $rows;
  }

}

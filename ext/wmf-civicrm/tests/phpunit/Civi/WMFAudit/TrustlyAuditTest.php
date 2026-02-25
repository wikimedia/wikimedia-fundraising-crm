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
      'contact_id' => $this->createIndividual([], 'chargeback'),
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
      'contact_id' => $this->createIndividual([], 'refund'),
      'total_amount' => 3.10,
      'receive_date' => 'February 7th, 2026 10:33 AM',
      'contribution_extra.gateway' => 'gravy',
      'financial_type_id:name' => 'Cash',
      'contribution_extra.gateway_txn_id' => '070c47b8-3b8e-48dc-8585-10c4a4020728',
      'contribution_extra.backend_processor' => 'trustly',
      'contribution_extra.backend_processor_txn_id' => '8090501261',
      'contribution_extra.payment_orchestrator_reconciliation_id' => 'DIYcExJDXRRFjjf4NLhEG',
      'invoice_number' => '12346.1',
    ]);

    // And this one is a bit nasty - create the original contribution in a recurring series.
    // The details we get 'look like' this one ... but they are not...
    $this->createTestEntity('ContributionRecur', [
      'contact_id' => $this->createIndividual([], 'recur'),
      'amount' => 5,
      'receive_date' => '	September 6th, 2025 2:34 AM',
      'payment_processor_id:name' => 'gravy',
      'financial_type_id:name' => 'Cash',
      'processor_id' => 'c05d965a-6209-4137-86e7-deafb53d76a9',
      'trxn_id' => 'c05d965a-6209-4137-86e7-deafb53d76a9',
    ], 'recur');
    $this->createTestEntity('Contribution', [
      'contribution_recur_id' => $this->ids['ContributionRecur']['recur'],
      'contact_id' => $this->ids['Contact']['recur'],
      'total_amount' => 5,
      'receive_date' => 'September 6th, 2025 2:34 AM',
      'contribution_extra.gateway' => 'gravy',
      'financial_type_id:name' => 'Cash',
      'contribution_extra.gateway_txn_id' => 'c05d965a-6209-4137-86e7-deafb53d76a9',
      'contribution_extra.backend_processor' => 'trustly',
      'contribution_extra.backend_processor_txn_id' => '7987701532',
      'invoice_number' => '12347.2',
    ]);

    // Also create one where the first contribution is NOT linked to the contribution_recur_id other
    // than by trxn_id. Use the 'other' pattern for trxn_id.
    // Since we can't create this one we just need to make sure it doesn't get 'confused' with the first
    // one.
    $this->createTestEntity('ContributionRecur', [
      'contact_id' => $this->createIndividual([], 'recur'),
      'amount' => 50,
      'receive_date' => '	September 6th, 2025 2:34 AM',
      'payment_processor_id:name' => 'gravy',
      'financial_type_id:name' => 'Cash',
      'processor_id' => '2992e0ce-6b1d-447a-80d1-2a4d11bbcb0f',
      'trxn_id' => 'RECURRING GRAVY 2992e0ce-6b1d-447a-80d1-2a4d11bbcb0f',
    ], 'recur2');
    $this->createTestEntity('Contribution', [
      'contact_id' => $this->ids['Contact']['recur'],
      'total_amount' => 50,
      'receive_date' => 'September 6th, 2025 2:34 AM',
      'contribution_extra.gateway' => 'gravy',
      'financial_type_id:name' => 'Cash',
      'contribution_extra.gateway_txn_id' => '2992e0ce-6b1d-447a-80d1-2a4d11bbcb0f',
      'contribution_extra.backend_processor' => 'trustly',
      'contribution_extra.backend_processor_txn_id' => '798770000',
      'invoice_number' => '12348.2',
    ]);

    // Run it twice so the one that is refunded gets a chance to 'take'
    $this->runAuditBatch('fun_file', 'P11KFUN-3618-20260201120000-20260202120000-0001of0001.csv');

    // Also create the two in the file. We know it can't create them but let's at least make sure they settle.
    $this->createTestEntity('Contribution', [
      'contribution_recur_id' => $this->ids['ContributionRecur']['recur'],
      'contact_id' => $this->ids['Contact']['recur'],
      'total_amount' => 5,
      'receive_date' => '2026-02-06T17:10:34.857Z',
      'contribution_extra.gateway' => 'gravy',
      'financial_type_id:name' => 'Cash',
      'contribution_extra.gateway_txn_id' => 'something random',
      'contribution_extra.backend_processor' => 'trustly',
      'contribution_extra.backend_processor_txn_id' => '8090016929',
    ]);
    $this->createTestEntity('Contribution', [
      'contact_id' => $this->ids['Contact']['recur'],
      'total_amount' => 5,
      'receive_date' => 'September 6th, 2025 2:34 AM',
      'contribution_extra.gateway' => 'gravy',
      'financial_type_id:name' => 'Cash',
      'contribution_extra.gateway_txn_id' => 'acd093cb-a454-4708-aeb1-f25702516dd0',
      'contribution_extra.backend_processor' => 'trustly',
      'contribution_extra.backend_processor_txn_id' => '8090016000',
    ]);

    $this->runAuditBatch('fun_file', 'P11KFUN-3618-20260201120000-20260202120000-0001of0001.csv', '999');
    $contribution = Contribution::get(FALSE)
      ->addSelect('contribution_extra.*')
      ->addWhere('contribution_extra.gateway', '=', 'gravy')
      ->addWhere('contribution_extra.gateway_txn_id', '=', '0e5d047c-e2ed-4bec-a3fc-156fccf64e13')
      ->execute()->single();
    $this->assertEquals('USD', $contribution['contribution_extra.original_currency']);

    // Make sure the original recurring contribution was not added to the batch.
    $contribution = Contribution::get(FALSE)
      ->addSelect('contribution_extra.*', 'contribution_settlement.*')
      ->addWhere('contribution_extra.gateway', '=', 'gravy')
      ->addWhere('contribution_extra.gateway_txn_id', '=', 'c05d965a-6209-4137-86e7-deafb53d76a9')
      ->execute()->single();
    $this->assertEmpty($contribution['contribution_settlement.settlement_batch_reference']);

    // Make sure a new recurring was.
    $contribution = Contribution::get(FALSE)
      ->addSelect('contribution_extra.*', 'contribution_settlement.*')
      ->addWhere('contribution_extra.backend_processor', '=', 'trustly')
      ->addWhere('contribution_extra.backend_processor_txn_id', '=', '8090016929')
      ->execute()->single();
    $this->assertEquals('trustly_999_USD', $contribution['contribution_settlement.settlement_batch_reference']);

    // now check the second type of cross referenced ones - where the first contribution is
    // not directly tied to the recurring series but we are still getting the gravy ID for it rather than the new one.
    // Make sure the original recurring contribution was not added to the batch.
    $contribution = Contribution::get(FALSE)
      ->addSelect('contribution_extra.*', 'contribution_settlement.*', 'total_amount')
      ->addWhere('contribution_extra.gateway', '=', 'gravy')
      ->addWhere('contribution_extra.gateway_txn_id', '=', '2992e0ce-6b1d-447a-80d1-2a4d11bbcb0f')
      ->execute()->single();
    $this->assertEmpty($contribution['contribution_settlement.settlement_batch_reference']);
    $this->assertEquals(50, $contribution['total_amount']);

    $contribution = Contribution::get(FALSE)
      ->addSelect('contribution_extra.*', 'contribution_settlement.*', 'total_amount')
      ->addWhere('contribution_extra.gateway', '=', 'gravy')
      ->addWhere('contribution_extra.gateway_txn_id', '=', 'acd093cb-a454-4708-aeb1-f25702516dd0')
      ->execute()->single();
    $this->assertEquals(5, $contribution['total_amount']);
    $this->assertEquals('trustly_999_USD', $contribution['contribution_settlement.settlement_batch_reference']);
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

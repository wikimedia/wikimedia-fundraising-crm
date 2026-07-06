<?php

namespace Civi\WMFAudit;

use Civi\Api4\Batch;
use Civi\Api4\Contribution;
use Civi\Api4\ContributionTracking;
use SmashPig\Core\Helpers\Base62Helper;
use SmashPig\Core\UtcDate;

/**
 * @group WMF
 * @group WMFAudit
 */
class CheckoutComAuditTest extends BaseAuditTestCase {

  protected bool $useIncomingDirectory = FALSE;

  protected string $gateway = 'CheckoutCom';

  public function tearDown(): void {
    Batch::delete(FALSE)
      ->addWhere('name', 'LIKE', 'checkoutcom_00000003K599_USD')
      ->execute();
    Contribution::delete(FALSE)
      ->addWhere('contribution_settlement.settlement_batch_reference', 'LIKE', 'checkoutcom_00000003K599_USD')
      ->execute();
    parent::tearDown();
  }

  public function testParseCheckoutComSettlementBreakdownFile(): void {
    $this->runAuditBatch('', 'settlement-breakdown_ent_testcheckoutfixture_20260702_00000003k599_1.csv', 'checkoutcom_00000003K599_USD');
    $contributions = Contribution::get(FALSE)
      ->addOrderBy('id', 'DESC')
      ->addWhere('contribution_settlement.settlement_batch_reference', '=', 'checkoutcom_00000003K599_USD')
      ->execute()->indexBy('trxn_id');
    $this->assertCount(9, $contributions);
    $batch = Batch::get(FALSE)
      ->addSelect('*', 'status_id:name')
      ->addWhere('name', '=', 'checkoutcom_00000003K599_USD')
      ->execute()->single();
    $this->assertEquals('total_verified', $batch['status_id:name']);
  }

  public function createTransactionLog(array $row): void {
    $trackingID = $this->getNextContributionTrackingID();
    $utmSource = "B2526_082914_esLA_m_p1_lg_twn_twin1_optIn0.no-LP.apple_amex";
    $this->ids['ContributionTracking'][] = ContributionTracking::save(FALSE)
      ->addRecord([
        'id' => $trackingID,
        'utm_source' => $utmSource,
      ])
      ->execute()->first()['id'];
    $gateway = $this->gateway;
    $gatewayTxnID = Base62Helper::toUuid($row['Reference']);
    $timestamp = UtcDate::getUtcTimestamp($row['Processed On']);
    $this->createTestEntity('TransactionLog', [
      'date' => $timestamp,
      'gateway' => 'gravy',
      'order_id' => '',
      'gateway_txn_id' => $gatewayTxnID,
      'message' => [
        "gateway_txn_id" => $gatewayTxnID,
        "response" => FALSE,
        "gateway_account" => "WikimediaDonations",
        "fee" => 0,
        "gross" => $row['Gross In Processing Currency'],
        "backend_processor" => 'checkoutcom',
        "backend_processor_txn_id" => $row['Payment ID'],
        "contribution_tracking_id" => $trackingID,
        "payment_orchestrator_reconciliation_id" => $row['Reference'],
        "currency" => $row['Processing Currency'],
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
        "date" => $timestamp,
      ],
    ], $gatewayTxnID);
  }

}

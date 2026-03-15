<?php

use Civi\Api4\ContributionTracking;
use Civi\WMFAudit\BaseAuditTestCase;
use Civi\WMFAudit\StripeAuditProcessor;
use PHPUnit\Framework\TestCase;
use SmashPig\Core\UtcDate;
use SmashPig\PaymentProviders\Stripe\Audit\StripeAudit;

class StripeAuditProcessorTest extends BaseAuditTestCase {
  protected string $gateway = 'stripe';

  public function tearDown(): void {
    parent::tearDown();
  }

  public function testSettlementReportDataset(): void {
    $this->createTestEntity('Contribution', [
      'contact_id' => $this->createIndividual(),
      'financial_type_id:name' => 'Cash',
      'total_amount' => 25,
      'GiftData.Channel' => 'Email',
      'invoice_id' => '24315.1',
      'contribution_extra.backend_processor' => 'gateway',
      'contribution_extra.backend_processor_txn_id' => 'pi_123',
      'trxn_id' => 'GRAVY unknowable',
    ]);
    $output = $this->runAuditBatch('reports', 'settlement_report.csv', 'stripe_123_USD');
    $batch = $output['batch']->first();
    $this->assertEquals(15, $batch['settled_total_amount']);
  }

  public function createTransactionLog(array $row): void {
    $orderParts = explode('.', $row['payment_metadata[external_identifier]'] ?? '');
    $trackingID = $orderParts[0];
    $utmSource = "B2526_082914_esLA_m_p1_lg_twn_twin1_optIn0.no-LP.apple_amex";
    $this->ids['ContributionTracking'][] = ContributionTracking::save(FALSE)
      ->addRecord([
        'id' => $trackingID,
        'utm_source' => $utmSource,
      ])
      ->execute()->first()['id'];
    $gateway = $this->gateway;
    // gateway_txn_id is not useful here as it is not returned to us (ie the gravy one).
    $gatewayTxnID = 'xyx' . $trackingID;
    $this->createTestEntity('TransactionLog', [
      'date' => $row['date'] ?? UtcDate::getUtcTimestamp( $row['created'] ),
      'gateway' => 'gravy',
      'gateway_account' => 'WikimediaDonations',
      'order_id' => $trackingID . '.1',
      'gateway_txn_id' => 'xyx' . $trackingID,
      'message' => [
        "gateway_txn_id" => $gatewayTxnID,
        "response" => FALSE,
        "gateway_account" => "WikimediaDonations",
        "fee" => 0,
        "gross" => $row['amount']['value'] ?? $row['gross'],
        "backend_processor" => 'stripe',
        "backend_processor_txn_id" => $row['payment_intent_id'],
        "contribution_tracking_id" => $trackingID,
        "payment_orchestrator_reconciliation_id" => NULL,
        "currency" => $row['amount']['currencyCode'] ?? $row['currency'],
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
        "date" => strtotime($row['date'] ?? UtcDate::getUtcTimestamp( $row['created'] )),
      ],
    ], $gatewayTxnID);
  }

}

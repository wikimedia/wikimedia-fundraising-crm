<?php

use Civi\Api4\Contribution;
use Civi\Api4\ContributionTracking;
use Civi\WMFAudit\BaseAuditTestCase;
use SmashPig\Core\UtcDate;

class StripeAuditTest extends BaseAuditTestCase {
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
    $this->assertEquals(25, $batch['settled_total_amount']);
  }

  /**
   * Give Lively's "Giving Basket" feature sends us a single Stripe Connect
   * transfer per charity, bundling many small gifts with no per-donor
   * billing details at all. settlement_report_giving_basket.csv has two
   * donations: an ordinary card donation with full billing details (Homer
   * Simpson), and a Giving Basket transfer (no billing details,
   * description contains "Give Lively / Giving Basket"). Running in
   * make-missing mode should link the Giving Basket gift to the existing
   * "Give Lively" organization contact instead of creating a blank
   * individual.
   *
   * Unlike testSettlementReportDataset(), this deliberately does not use
   * runAuditBatch()/prepareForAuditProcessing(): this class's
   * createTransactionLog() override would seed a matching TransactionLog
   * row for every CSV row, which makes the audit treat every transaction
   * as already found rather than missing - defeating the point of this
   * make-missing test.
   */
  public function testGivingBasketDonationLinksToOrganization(): void {
    $organizationID = $this->createOrganization(['organization_name' => 'Give Lively'], 'give_lively');

    $this->setAuditDirectory('reports');
    $this->runAuditor('settlement_report_giving_basket.csv', '', TRUE);
    $this->processQueues();

    $givingBasketContribution = Contribution::get(FALSE)
      ->addWhere('invoice_id', '=', '24316.1')
      ->addSelect('id', 'contact_id', 'total_amount')
      ->execute()->single();
    $this->assertEquals($organizationID, $givingBasketContribution['contact_id']);
    $this->assertEquals(1780.11, $givingBasketContribution['total_amount']);
    $this->ids['Contribution'][] = $givingBasketContribution['id'];

    $homerContribution = Contribution::get(FALSE)
      ->addWhere('invoice_id', '=', '24315.1')
      ->execute()->first();
    $this->assertNotNull($homerContribution);
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

<?php

namespace Civi\WMFAudit;

use Civi\Api4\ContributionTracking;
use SmashPig\Core\Context;
use SmashPig\Tests\TestingProviderConfiguration;

/**
 * @group Gravy
 * @group WmfAudit
 */
class GravyAuditTest extends BaseAuditTestCase {

  protected string $gateway = 'gravy';

  public function setUp(): void {
    parent::setUp();
    $this->initializeGravySmashPigConfig();
    $this->seedContributionTrackingData();
  }

  protected function initializeGravySmashPigConfig(): void {
    $ctx = Context::get();
    $config = TestingProviderConfiguration::createForProvider('gravy', $ctx->getGlobalConfiguration());
    $ctx->setProviderConfiguration($config);
  }

  protected function seedContributionTrackingData(): void {
    ContributionTracking::save(FALSE)
      ->addRecord([
        'id' => 113,
        'utm_medium' => 'gravy_audit',
        'utm_campaign' => 'gravy_audit',
      ])
      ->execute();
  }

  /**
   * This test sets up the gravy audit processor the process the donations all transactions report located at:
   * tests/phpunit/Civi/WMFAudit/data/Gravy/donations/gravy/incoming/gravy_all_transactions_report_2024_09_13.csv
   * and then asserts that queue message was added for the "missing" 113 transaction in getExpectedDonationMessage().
   *
   * The code internals rely on the test fixture payments log data in
   * tests/phpunit/Civi/WMFAudit/data/logs/payments-gravy-20240913.gz
   * to confirm record of the dummy transaction and add it to the donation
   * queue, which we then confirm in $this->assertMessages().
   *
   */
  public function testParseDonations(): void {
    $this->setAuditDirectory('donations');
    $this->runAuditor();
    $this->assertMessages(['donations' => $this->getExpectedDonationMessage()]);
  }

  /**
   * This test sets up the gravy audit processor the process the refund all transactions report located at:
   * tests/phpunit/Civi/WMFAudit/data/Gravy/refund/incoming/gravy_all_transactions_report_2024_09_13.csv
   * and confirms a refund queue message was pushed to the queue.
   */
  public function testParseRefunds(): void {
    // add seed donation that we're going to later try to refund
    $seedDonationMessage = $this->getExpectedDonationMessage();
    $this->processDonationMessage($seedDonationMessage[0], FALSE);
    $this->ids['Contribution']['refund_test'] = $this->getContributionForMessage($seedDonationMessage[0])['id'];

    $this->setAuditDirectory('refund');
    $this->runAuditor();
    $this->assertMessages(['refund' => $this->getExpectedRefundMessage()]);
  }

  protected function getExpectedDonationMessage(): array {
    return [
      [
        'gateway' => 'gravy',
        'order_id' => '113.10',
        'currency' => 'USD',
        'date' => 1726234885,
        'settled_date' => NULL,
        'gateway_txn_id' => '3754736e-9a6e-44fe-a43b-835f8d78c89b',
        'gross' => '35.00',
        'invoice_id' => '113.10',
        'payment_method' => 'cc',
        'email' => 'jwales@example.com',
        'first_name' => 'Jimmy',
        'last_name' => 'Wales',
        'settled_currency' => 'USD',
        'gross_currency' => 'USD',
        'contribution_tracking_id' => '113',
        'utm_medium' => 'gravy_audit',
        'utm_source' => '..cc',
        'country' => 'US',
        'city' => 'San Francisco',
        'gateway_account' => 'WikimediaDonations',
        'language' => 'en',
        'payment_submethod' => 'visa',
        'postal_code' => '94104',
        'recurring' => '',
        'state_province' => 'CA',
        'street_address' => '1 Montgomery Street',
        'user_ip' => '172.18.0.1',
        'utm_campaign' => 'gravy_audit',
        'backend_processor' => 'adyen',
      ],
    ];
  }

  protected function getExpectedRefundMessage(): array {
    return [
      [
        'gateway' => 'gravy',
        'date' => 1726234891,
        'gross' => 35,
        'gross_currency' => 'USD',
        'type' => 'refund',
        // Gravy doesn't provide a distinct ID for refunds, so we use the trxn ID
        // for both refund_id and parent_id
        'gateway_refund_id' => '3754736e-9a6e-44fe-a43b-835f8d78c89b',
        'gateway_parent_id' => '3754736e-9a6e-44fe-a43b-835f8d78c89b',
        'settlement_batch_reference' => NULL,
        'settled_total_amount' => NULL,
        'settled_fee_amount' => NULL,
        'settled_net_amount' => NULL,
        'settled_currency' => 'USD',
        'original_currency' => NULL,
        'settled_date' => NULL,
        'original_net_amount' => NULL,
        'original_fee_amount' => NULL,
        'original_total_amount' => NULL,
      ],
    ];
  }

  protected function setAuditDirectory(string $subDir): void {
    $directory = __DIR__ . '/data/Gravy/' . $subDir;
    \Civi::settings()->set('wmf_audit_directory_audit', $directory);
  }

}

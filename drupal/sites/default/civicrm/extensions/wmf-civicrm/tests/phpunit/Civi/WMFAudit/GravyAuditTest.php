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
    $ctx = Context::get();
    $config = TestingProviderConfiguration::createForProvider('gravy', $ctx->getGlobalConfiguration());
    $ctx->setProviderConfiguration($config);

    ContributionTracking::save(FALSE)
      ->addRecord([
        'id' => 113,
        'utm_medium' => 'gravy_audit',
        'utm_campaign' => 'gravy_audit',
      ])
      ->execute();

  }

  public function auditTestProvider(): array {
    return [
      'donations' => [
        __DIR__ . '/data/Gravy/donations',
        [
          'donations' => [
            [
              'gateway' => 'gravy',
              'order_id' => '113.10',
              'currency' => 'USD',
              'date' => 1726234885,
              'gateway_txn_id' => '3754736e-9a6e-44fe-a43b-835f8d78c89b',
              'gross' => '35.00',
              'invoice_id' => '113.10',
              'payment_method' => 'cc',
              'email' => 'jwales@example.com',
              'first_name' => 'Jimmy',
              'last_name' => 'Wales',
              'settled_gross' => 35,
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
              'backend_processor' => 'adyen'
            ],
          ],
        ],
      ],
    ];
  }

  /**
   * This test sets up the gravy audit processor to process the file located at
   * tests/phpunit/Civi/WMFAudit/data/Gravy/donations/gravy/incoming/gravy_settlement_report_2024_09_13.csv
   * and then asserts that a queue message was added for the "missing" 113.10
   * transactions in the array above.
   *
   * The code internals rely on the test fixture payments log data in
   * tests/phpunit/Civi/WMFAudit/data/logs/payments-gravy-20240913.gz
   * to confirm record of the dummy transaction and add it to the donation
   * queue, which we then confirm in $this->assertMessages().
   *
   * @dataProvider auditTestProvider
   * @throws \Exception
   */
  public function testParseFiles($path, $expectedMessages) {
    \Civi::settings()->set('wmf_audit_directory_audit', $path);

    $this->runAuditor();

    $this->assertMessages($expectedMessages);
  }

}

<?php

namespace Civi\WMFAudit;

use SmashPig\Core\Context;
use SmashPig\PaymentProviders\Amazon\Tests\AmazonTestConfiguration;

/**
 * @group Amazon
 * @group WmfAudit
 */
class AmazonAuditTest extends BaseAuditTestCase {

  protected string $gateway = 'amazon';

  /**
   * @throws \Civi\WMFException\WMFException
   * @throws \CRM_Core_Exception
   */
  public function setUp(): void {
    parent::setUp();

    // Use the test configuration for SmashPig
    $ctx = Context::get();
    $config = AmazonTestConfiguration::instance($ctx->getGlobalConfiguration());
    $ctx->setProviderConfiguration($config);

    $msg = [
      'contribution_tracking_id' => 2476135333,
      'currency' => 'USD',
      'date' => 1443724034,
      'email' => 'mouse@wikimedia.org',
      'gateway' => 'amazon',
      'gateway_txn_id' => 'P01-4968629-7654321-C070794',
      'gross' => 1.00,
      'payment_method' => 'amazon',
    ];
    $this->processMessageWithoutQueuing($msg, 'Donation', 'test');
  }

  public function auditTestProvider(): array {
    return [
      'donations' => [
        __DIR__ . '/data/Amazon/donation/',
        'donation' => [
          'donations' => [
            [
              'contribution_tracking_id' => '87654321',
              'country' => 'US',
              'currency' => 'USD',
              'settled_currency' => 'USD',
              'settled_date' => NULL,
              'date' => 1443723034,
              'email' => 'nonchalant@gmail.com',
              'fee' => '0.59',
              'first_name' => 'Test',
              'gateway' => 'amazon',
              'gateway_account' => 'default',
              'gateway_txn_id' => 'P01-1488694-1234567-C034811',
              'gross' => '10.00',
              'invoice_id' => '87654321-0',
              'language' => 'en',
              'last_name' => 'Mouse',
              'order_id' => '87654321-0',
              'payment_method' => 'amazon',
              'payment_submethod' => '',
              'recurring' => '',
              'user_ip' => '1.2.3.4',
              'utm_campaign' => 'C13_en.wikipedia.org',
              'utm_medium' => 'sidebar',
              'utm_source' => '..amazon',
              'tracking_date' => '2015-10-01 18:10:34',
            ],
          ],
        ],
      ],
      'refund' => [
        __DIR__ . '/data/Amazon/refund/',
        [
          'refund' => [
            [
              'date' => 1444087249,
              'gateway' => 'amazon',
              'gateway_parent_id' => 'P01-4968629-7654321-C070794',
              'gateway_refund_id' => 'P01-4968629-7654321-R017571',
              'gross' => '1.00',
              'gross_currency' => 'USD',
              'type' => 'refund',
            ],
          ],
        ],
      ],
      'chargeback' => [
        __DIR__ . '/data/Amazon/chargeback/',
        [
          'refund' => [
            [
              'date' => 1444087249,
              'gateway' => 'amazon',
              'gateway_parent_id' => 'P01-4968629-7654321-C070794',
              'gateway_refund_id' => 'P01-4968629-7654321-R017571',
              'gross' => '1.00',
              'gross_currency' => 'USD',
              'type' => 'chargeback',
            ],
          ],
        ],
      ],
    ];
  }

  /**
   * @dataProvider auditTestProvider
   */
  public function testParseFiles(string $path, array $expectedMessages): void {
    \Civi::settings()->set('wmf_audit_directory_audit', $path);

    $this->runAuditor();

    $this->assertMessages($expectedMessages);
  }

}

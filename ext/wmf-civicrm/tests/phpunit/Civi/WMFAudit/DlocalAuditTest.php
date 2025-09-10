<?php

namespace Civi\WMFAudit;

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
      'contribution_tracking_id' => 2476135333,
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
              'gross' => 5,
              'invoice_id' => '26683111.0',
              'language' => 'en',
              'last_name' => 'Mouse',
              'order_id' => '26683111.0',
              'payment_method' => 'cc',
              'payment_submethod' => 'mc',
              'recurring' => '',
              'settled_currency' => 'USD',
              'settled_date' => 1434477632,
              'settled_fee_amount' => '0.03',
              'settled_gross' => '1.50',
              'user_ip' => '1.2.3.4',
              'utm_campaign' => 'C13_en.wikipedia.org',
              'utm_medium' => 'sidebar',
              'utm_source' => '..cc',
              'tracking_date' => '2015-06-16 17:59:12',
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
              'contribution_tracking_id' => '2476135999',
              'country' => 'BR',
              'currency' => 'BRL',
              'date' => 1434506370,
              'email' => 'jimmy@bankster.com',
              'first_name' => 'Jimmy',
              'gateway' => 'dlocal',
              'gateway_account' => 'default',
              'gateway_txn_id' => '5258777',
              'gross' => 4,
              'invoice_id' => '2476135999.0',
              'language' => 'en',
              'last_name' => 'Mouse',
              'order_id' => '2476135999.0',
              'payment_method' => 'bt',
              'payment_submethod' => 'bradesco',
              'recurring' => '',
              'settled_currency' => 'USD',
              'settled_date' => 1434506459,
              'settled_fee_amount' => '0.03',
              'settled_gross' => '1.20',
              'user_ip' => '8.8.8.8',
              'utm_campaign' => 'C13_en.wikipedia.org',
              'utm_medium' => 'sidebar',
              'utm_source' => '..bt',
              'tracking_date' => '2015-06-17 01:59:30',
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
    \Civi::settings()->set('wmf_audit_directory_audit', $path);

    $this->runAuditor();

    $this->assertMessages($expectedMessages);
    $this->assertLoglinesPresent($expectedLoglines);
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

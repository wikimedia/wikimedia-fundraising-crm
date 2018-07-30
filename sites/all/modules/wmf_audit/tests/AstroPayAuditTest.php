<?php

function astropay_audit_watchdog($entry) {
  AstroPayAuditTest::receiveLogline($entry);
}

/**
 * @group AstroPay
 * @group WmfAudit
 */
class AstroPayAuditTest extends BaseAuditTestCase {

  static protected $loglines;

  protected $contact_id;

  protected $contribution_id;

  public static function getInfo() {
    return [
      'name' => 'AstroPay Audit',
      'group' => 'Audit',
      'description' => 'Parse audit files and match with logs.',
    ];
  }

  public function setUp() {
    parent::setUp();
    $dirs = [
      'wmf_audit_log_archive_dir' => __DIR__ . '/data/logs/',
      'astropay_audit_recon_completed_dir' => $this->getTempDir(),
      'astropay_audit_working_log_dir' => $this->getTempDir(),
    ];

    foreach ($dirs as $var => $dir) {
      if (!is_dir($dir)) {
        mkdir($dir);
      }
      variable_set($var, $dir);
    }

    $old_working = glob($dirs['astropay_audit_working_log_dir'] . '*');
    foreach ($old_working as $zap) {
      if (is_file($zap)) {
        unlink($zap);
      }
    }

    variable_set('astropay_audit_log_search_past_days', 7);

    // Fakedb doesn't fake the original txn for refunds, so add one here
    // First we need to set an exchange rate for a sickeningly specific time
    $this->setExchangeRates(1434488406, ['BRL' => 3.24]);
    $this->setExchangeRates(1434488406, ['USD' => 1]);
    $existing = wmf_civicrm_get_contributions_from_gateway_id('astropay', '5138333');
    if ($existing) {
      // Previous test run may have crashed before cleaning up
      $contribution = $existing[0];
    }
    else {
      $msg = [
        'contribution_tracking_id' => 2476135333,
        'currency' => 'BRL',
        'date' => 1434488406,
        'email' => 'lurch@yahoo.com',
        'gateway' => 'ASTROPAY',
        'gateway_txn_id' => '5138333',
        'gross' => 5.00,
        'payment_method' => 'cc',
        'payment_submethod' => 'mc',
      ];
      $contribution = wmf_civicrm_contribution_message_import($msg);
    }
    $this->contact_id = $contribution['contact_id'];
    $this->contribution_id = $contribution['id'];
  }

  public function tearDown() {
    $this->callAPISuccess('Contribution', 'delete', ['id' => $this->contribution_id]);
    $this->callAPISuccess('Contact', 'delete', ['id' => $this->contact_id]);
    parent::tearDown();
  }

  public function auditTestProvider() {
    return [
      [
        __DIR__ . '/data/AstroPay/donation/',
        [
          'donations' => [
            [
              'contribution_tracking_id' => '26683111',
              'country' => 'BR',
              'currency' => 'BRL',
              'date' => 1434477552,
              'email' => 'nonchalant@gmail.com',
              'first_name' => 'Test',
              'gateway' => 'astropay',
              'gateway_account' => 'default',
              'gateway_txn_id' => '5258111',
              'gross' => '5',
              'language' => 'en',
              'last_name' => 'Person',
              'order_id' => '26683111.0',
              'payment_method' => 'cc',
              'payment_submethod' => 'mc',
              'recurring' => '',
              'settled_currency' => 'USD',
              'settled_date' => 1434477632,
              'settled_fee' => '0.03',
              'settled_gross' => '1.50',
              'user_ip' => '1.2.3.4',
              'utm_campaign' => 'C13_en.wikipedia.org',
              'utm_medium' => 'sidebar',
              'utm_source' => '..cc',
            ],
          ],
        ],
        [],
      ],
      [
        __DIR__ . '/data/AstroPay/bt/',
        [
          'donations' => [
            [
              'contribution_tracking_id' => '2476135999',
              'country' => 'BR',
              'currency' => 'BRL',
              'date' => 1434506370,
              'email' => 'jimmy@bankster.com',
              'first_name' => 'Jimmy',
              'gateway' => 'astropay',
              'gateway_account' => 'default',
              'gateway_txn_id' => '5258777',
              'gross' => '4',
              'language' => 'en',
              'last_name' => 'Bankster',
              'order_id' => '2476135999.0',
              'payment_method' => 'bt',
              'payment_submethod' => 'bradesco',
              'recurring' => '',
              'settled_currency' => 'USD',
              'settled_date' => 1434506459,
              'settled_fee' => '0.03',
              'settled_gross' => '1.20',
              'user_ip' => '8.8.8.8',
              'utm_campaign' => 'C13_en.wikipedia.org',
              'utm_medium' => 'sidebar',
              'utm_source' => '..bt',
            ],
          ],
        ],
        [],
      ],
      [
        __DIR__ . '/data/AstroPay/refund/',
        [
          'refund' => [
            [
              'date' => 1434488406,
              'gateway' => 'astropay',
              'gateway_parent_id' => '5138333',
              'gateway_refund_id' => '33333',
              'gross' => '5.00',
              'gross_currency' => 'BRL',
              'type' => 'refund',
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
    variable_set('astropay_audit_recon_files_dir', $path);

    $this->runAuditor();

    $this->assertMessages($expectedMessages);
    $this->assertLoglinesPresent($expectedLoglines);
  }

  protected function runAuditor() {
    $options = [
      'fakedb' => TRUE,
      'quiet' => TRUE,
      'test' => TRUE,
      #'verbose' => 'true', # Uncomment to debug.
    ];
    $audit = new AstroPayAuditProcessor($options);
    $audit->run();
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
      $this->fail("Did not see these loglines, " . json_encode($notFound));
    }
  }

  static public function receiveLogline($entry) {
    self::$loglines[] = $entry;
  }
}

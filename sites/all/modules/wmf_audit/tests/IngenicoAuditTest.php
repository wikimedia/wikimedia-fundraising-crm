<?php

/**
 * @group Ingenico
 * @group WmfAudit
 */
class IngenicoAuditTest extends BaseAuditTestCase {

  protected $contact_ids = [];

  protected $contribution_ids = [];

  public function setUp() {
    parent::setUp();

    $dirs = [
      'wmf_audit_log_archive_dir' => __DIR__ . '/data/logs/',
      'ingenico_audit_recon_completed_dir' => $this->getTempDir(),
      'ingenico_audit_working_log_dir' => $this->getTempDir(),
    ];

    foreach ($dirs as $var => $dir) {
      if (!is_dir($dir)) {
        mkdir($dir);
      }
      variable_set($var, $dir);
    }

    $old_working = glob($dirs['ingenico_audit_working_log_dir'] . '*');
    foreach ($old_working as $zap) {
      if (is_file($zap)) {
        unlink($zap);
      }
    }

    variable_set('ingenico_audit_log_search_past_days', 7);

    // Fakedb doesn't fake the original txn for refunds, so add one here
    $existing = wmf_civicrm_get_contributions_from_gateway_id('globalcollect', '11992288');
    if ($existing) {
      // Previous test run may have crashed before cleaning up
      $contribution = $existing[0];
    }
    else {
      $msg = [
        'contribution_tracking_id' => 8675309,
        'currency' => 'USD',
        'date' => 1455825706,
        'email' => 'nun@flying.com',
        'gateway' => 'globalcollect',
        'gateway_txn_id' => '11992288',
        'gross' => 100.00,
        'payment_method' => 'cc',
        'payment_submethod' => 'visa',
      ];
      $contribution = wmf_civicrm_contribution_message_import($msg);
    }
    $this->contact_ids[] = $contribution['contact_id'];

    // and another for the chargeback
    $existing = wmf_civicrm_get_contributions_from_gateway_id('globalcollect', '55500002');
    if ($existing) {
      // Previous test run may have crashed before cleaning up
      $contribution = $existing[0];
    }
    else {
      $msg = [
        'contribution_tracking_id' => 5318008,
        'currency' => 'USD',
        'date' => 1443724034,
        'email' => 'lovelyspam@python.com',
        'gateway' => 'globalcollect',
        'gateway_txn_id' => '55500002',
        'gross' => 200.00,
        'payment_method' => 'cc',
        'payment_submethod' => 'visa',
      ];
      $contribution = wmf_civicrm_contribution_message_import($msg);
    }
    $this->contact_ids[] = $contribution['contact_id'];

    // and another for the sparse refund
    $this->setExchangeRates(1443724034, ['EUR' => 1.5, 'USD' => 1]);
    $existing = wmf_civicrm_get_contributions_from_gateway_id('globalcollect', '1111662235');
    if ($existing) {
      // Previous test run may have crashed before cleaning up
      $contribution = $existing[0];
    }
    else {
      db_merge('contribution_tracking')->key([
        'id' => 48987654,
      ])->fields([
        'country' => 'IT',
        'utm_source' => 'something',
        'utm_medium' => 'another_thing',
        'utm_campaign' => 'campaign_thing',
        'language' => 'it',
      ])->execute();
      $msg = [
        'contribution_tracking_id' => 48987654,
        'currency' => 'EUR',
        'date' => 1443724034,
        'email' => 'lovelyspam@python.com',
        'gateway' => 'globalcollect',
        'gateway_txn_id' => '1111662235',
        'gross' => 15.00,
        'payment_method' => 'cc',
        'payment_submethod' => 'visa',
      ];
      $contribution = wmf_civicrm_contribution_message_import($msg);
    }
    $this->contact_ids[] = $contribution['contact_id'];
  }

  public function tearDown() {
    foreach ($this->contact_ids as $contact_id) {
      $this->cleanUpContact($contact_id);
    }
  }

  public function auditTestProvider() {
    return [
      [
        __DIR__ . '/data/Ingenico/donation/',
        [
          'donations' => [
            [
              'contribution_tracking_id' => '5551212',
              'country' => 'US',
              'currency' => 'USD',
              'date' => 1501368968,
              'email' => 'dutchman@flying.net',
              'first_name' => 'Arthur',
              'gateway' => 'globalcollect',
              'gateway_account' => 'whatever',
              'gateway_txn_id' => '987654321',
              'gross' => '3.00',
              'installment' => 1,
              'language' => 'en',
              'last_name' => 'Aardvark',
              'order_id' => '987654321',
              'payment_method' => 'cc',
              'payment_submethod' => 'visa',
              'recurring' => '',
              'user_ip' => '111.222.33.44',
              'utm_campaign' => 'ingenico_audit',
              'utm_medium' => 'ingenico_audit',
              'utm_source' => '..cc',
              'street_address' => '1111 Fake St',
              'city' => 'Denver',
              'state_province' => 'CO',
              'postal_code' => '87654',
              'invoice_id' => '5551212.68168',
              'merchant_id' => '1234'
            ],
          ],
        ],
      ],
      [
        __DIR__ . '/data/Ingenico/lastDayOfMonth/',
        [
          'donations' => [
            [
              'contribution_tracking_id' => '57123456',
              'country' => 'NL',
              'currency' => 'EUR',
              'date' => 1525114284,
              'email' => 'a.donor@example.org',
              'first_name' => 'Wiki',
              'gateway' => 'globalcollect',
              'gateway_account' => 'default',
              'gateway_txn_id' => '9812345678',
              'gross' => '3.00',
              'installment' => '1',
              'language' => 'nl',
              'last_name' => 'Superfan',
              'order_id' => '9812345678',
              'payment_method' => 'rtbt',
              'payment_submethod' => 'rtbt_ideal',
              'recurring' => '',
              'user_ip' => '11.22.33.44',
              'utm_campaign' => 'C1718_nlNL_m_FR',
              'utm_medium' => 'sitenotice',
              'utm_source' => 'B1718_0429_nlNL_m_p1_lg_txt_cnt.no-LP.rtbt.rtbt_ideal',
              'street_address' => 'N0NE PROVIDED',
              'city' => 'NOCITY',
              'invoice_id' => '57123456.84401',
              'merchant_id' => '1234'
            ],
          ],
        ],
      ],
      [
        __DIR__ . '/data/Ingenico/refund/',
        [
          'refund' => [
            [
              'date' => 1500942220,
              'gateway' => 'globalcollect',
              'gateway_parent_id' => '11992288',
              'gateway_refund_id' => '11992288',
              'gross' => '100.00',
              'gross_currency' => 'USD',
              'type' => 'refund',
            ],
          ],
        ],
      ],
      [
        __DIR__ . '/data/Ingenico/sparseRefund/',
        [
          'refund' => [
            [
              'date' => 1503964800,
              'gateway' => 'globalcollect',
              'gateway_parent_id' => '1111662235',
              'gateway_refund_id' => '1111662235',
              'gross' => '15.00',
              'gross_currency' => 'EUR',
              'type' => 'refund',
            ],
          ],
        ],
      ],
      [
        __DIR__ . '/data/Ingenico/chargeback/',
        [
          'refund' => [
            [
              'date' => 1495023569,
              'gateway' => 'globalcollect',
              'gateway_parent_id' => '55500002',
              'gateway_refund_id' => '55500002',
              'gross' => '200.00',
              'gross_currency' => 'USD',
              'type' => 'chargeback',
            ],
          ],
        ],
      ],
      [
        __DIR__ . '/data/Ingenico/recurring/',
        [
          'donations' => [
            [
              'contribution_tracking_id' => '55599991',
              'country' => 'US',
              'currency' => 'USD',
              'date' => 1512602101,
              'email' => 'donor123@example.net',
              'first_name' => 'DonorFirst',
              'gateway' => 'globalcollect',
              'gateway_account' => 'default',
              'gateway_txn_id' => '2987654321',
              'gross' => '1.00',
              'installment' => 1,
              'language' => 'en',
              'last_name' => 'DonorLast',
              'order_id' => '2987654321',
              'payment_method' => 'cc',
              'payment_submethod' => 'mc',
              'recurring' => 1,
              'user_ip' => '11.22.33.44',
              'utm_campaign' => 'spontaneous',
              'utm_medium' => 'spontaneous',
              'utm_source' => 'fr-redir.default~default~default~default~control.rcc',
              'street_address' => '123 Fake St',
              'city' => 'Cityville',
              'state_province' => 'OR',
              'postal_code' => '12345',
              'invoice_id' => '55599991.635',
              'merchant_id' => '8765'
            ],
          ],
        ],
      ],
      [
        __DIR__ . '/data/Ingenico/combined/',
        [
          'donations' => [
            [
              'contribution_tracking_id' => '57123456',
              'country' => 'NL',
              'currency' => 'EUR',
              'date' => 1528051884,
              'email' => 'a.donor@example.org',
              'first_name' => 'Wiki',
              'gateway' => 'globalcollect',
              'gateway_account' => 'default',
              'gateway_txn_id' => '9812345678',
              'gross' => '3.00',
              'installment' => '1',
              'language' => 'nl',
              'last_name' => 'Superfan',
              'order_id' => '9812345678',
              'payment_method' => 'cc',
              'payment_submethod' => 'mc',
              'recurring' => '',
              'user_ip' => '11.22.33.44',
              'utm_campaign' => 'C1718_nlNL_m_FR',
              'utm_medium' => 'sitenotice',
              'utm_source' => 'B1718_0429_nlNL_m_p1_lg_txt_cnt.no-LP..cc',
              'street_address' => 'N0NE PROVIDED',
              'city' => 'NOCITY',
              'invoice_id' => '57123456.84401',
              'merchant_id' => '1234',
            ],
            [
              'contribution_tracking_id' => '5551212',
              'country' => 'US',
              'currency' => 'USD',
              'date' => 1528065428,
              'email' => 'dutchman@flying.net',
              'first_name' => 'Arthur',
              'gateway' => 'ingenico',
              'gateway_txn_id' => '000000123409876543210000100001',
              'gross' => '3',
              'installment' => 1,
              'language' => 'en',
              'last_name' => 'Aardvark',
              'order_id' => '5551212.1',
              'payment_method' => 'cc',
              'payment_submethod' => 'visa',
              'recurring' => '',
              'utm_campaign' => 'ingenico_audit',
              'utm_medium' => 'ingenico_audit',
              'utm_source' => '..cc',
              'street_address' => '1111 Fake St',
              'city' => 'Denver',
              'state_province' => 'CO',
              'postal_code' => '87654',
              'invoice_id' => '5551212.1',
              'merchant_id' => '1234',
              'user_ip' => '111.222.33.44',
            ],
          ],
        ],
      ],
    ];
  }

  /**
   * @dataProvider auditTestProvider
   */
  public function testParseFiles($path, $expectedMessages) {
    variable_set('ingenico_audit_recon_files_dir', $path);

    $this->runAuditor();

    $this->assertMessages($expectedMessages);
  }

  protected function runAuditor() {
    $options = [
      'fakedb' => TRUE,
      'quiet' => TRUE,
      'test' => TRUE,
      #'verbose' => 'true', # Uncomment to debug.
    ];
    $audit = new IngenicoAuditProcessor($options);
    $audit->run();
  }

}

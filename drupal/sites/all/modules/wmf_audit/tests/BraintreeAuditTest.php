<?php

use Civi\Api4\Contribution;

/**
 * @group Braintree
 * @group WmfAudit
 */
class BraintreeAuditTest extends \Civi\WMFAudit\BaseAuditTestCase {

  public function setUp(): void {
    parent::setUp();
    $dirs = [
      'wmf_audit_log_archive_dir' => __DIR__ . '/data/logs/',
      'braintree_audit_recon_completed_dir' => $this->getTempDir(),
      'braintree_audit_working_log_dir' => $this->getTempDir(),
    ];

    foreach ($dirs as $var => $dir) {
      if (!is_dir($dir)) {
        mkdir($dir);
      }
      variable_set($var, $dir);
    }

    $old_working = glob($dirs['braintree_audit_working_log_dir'] . '*');
    foreach ($old_working as $zap) {
      if (is_file($zap)) {
        unlink($zap);
      }
    }

    variable_set('braintree_audit_log_search_past_days', 7);

    // Fakedb doesn't fake the original txn for refunds, so add one here
    $existing = wmf_civicrm_get_contributions_from_gateway_id('braintree', 'dHJhbnNhY3Rpb25fMTYxZXdrMjk');

    if ($existing) {
      // Previous test run may have crashed before cleaning up
      $contribution = $existing[0];
    } else {
      $msg = [
        'gateway' => 'braintree',
        'date' => 1656383927,
        'gross' => '10.0',
        'contribution_tracking_id' => '34',
        'currency' => 'USD',
        'email' => 'donor@gmail.com',
        'gateway_txn_id' => 'dHJhbnNhY3Rpb25fMTYxZXdrMjk',
        'invoice_id' => '34.1',
        'phone' => null,
        'first_name' => 'donor',
        'last_name' => 'test',
        'payment_method' => 'paypal',
      ];
      $contribution = wmf_civicrm_contribution_message_import($msg);
    }
    $this->contact_id = $contribution['contact_id'];
    $this->contribution_id = $contribution['id'];
    // and another for the dispute
    $existing = wmf_civicrm_get_contributions_from_gateway_id('braintree', 'dHJhbnNhY3Rpb25fa2F4eG1ycjE');
    if ($existing) {
      // Previous test run may have crashed before cleaning up
      $contribution = $existing[0];
    } else {
      $msg = [
        'gateway' => 'braintree',
        'date' => 1656390820,
        'gross' => '3.33',
        'contribution_tracking_id' => '17',
        'currency' => 'USD',
        'email' => 'fr-tech+donor@wikimedia.org',
        'gateway_txn_id' => 'dHJhbnNhY3Rpb25fa2F4eG1ycjE',
        'invoice_id' => '17.1',
        'phone' => null,
        'first_name' => 'f',
        'last_name' => 'Mouse',
        'payment_method' => 'paypal',
      ];
      $contribution = wmf_civicrm_contribution_message_import($msg);
    }
    $this->contact_id = $contribution['contact_id'];
    $this->contribution_id = $contribution['id'];

    $msg = [
      'gateway' => 'braintree',
      'date' => 1656390820,
      'gross' => '1.00',
      'contribution_tracking_id' => '1004.0',
      'invoice_id' => '1004.0',
      'currency' => 'USD',
      'email' => 'fr-tech+donor@wikimedia.org',
      'gateway_txn_id' => 'dHJhbnNhY3Rpb25fa2F4eG1yfff',
      'phone' => null,
      'first_name' => 'f',
      'last_name' => 'Mouse',
      'payment_method' => 'paypal',
    ];
    $contribution = wmf_civicrm_contribution_message_import($msg);
    $this->ids['Contact']['test'] = $this->contact_id = $contribution['contact_id'];
    $this->contribution_id = $contribution['id'];
    $this->ids['Contribution']['refund_test'] = $contribution['id'];
  }

  public function auditTestProvider() {
    return [
      [
        __DIR__ . '/data/Braintree/donation/',
        [
          'donations' => [
            [
              'gateway' => 'braintree',
              'date' => 1656398525,
              'gross' => '4.50',
              'contribution_tracking_id' => '35',
              'currency' => 'USD',
              'email' => 'donor@gmail.com',
              'gateway_txn_id' => 'dHJhbnNhY3Rpb25fa2szNmZ4Y3A',
              'invoice_id' => '35.1',
              'phone' => null,
              'first_name' => 'donor',
              'last_name' => 'test',
              'payment_method' => 'paypal',
              'utm_source' => '..paypal',
              'utm_medium' => 'braintree_audit',
              'utm_campaign' => 'braintree_audit',
              'country' => 'US',
              'gateway_account' => 'test',
              'language' => 'en',
              'payment_submethod' => '',
              'recurring' => '',
              'user_ip' => '172.19.0.1',
              'order_id' => '35.1'
            ],
          ],
        ],
      ],
      [
        __DIR__ . '/data/Braintree/refund/',
        [
          "refund" => [
            [
              'gateway' => 'braintree',
              'date' => 1656390820,
              'gross' => '10.00',
              'gateway_parent_id' => 'dHJhbnNhY3Rpb25fMTYxZXdrMjk',
              'gateway_refund_id' => 'cmVmdW5kXzR6MXlyZ3o1',
              'type' => 'refund',
              'gross_currency' => 'USD',
            ]
          ]
        ],
      ],
      [
        __DIR__ . '/data/Braintree/chargeback/',
        [
          "refund" =>  [
            [
              'gateway' => 'braintree',
              'date' => 1656381367,
              'gross' => '3.33',
              'gateway_parent_id' => 'dHJhbnNhY3Rpb25fa2F4eG1ycjE',
              'gateway_refund_id' => 'dHJhbnNhY3Rpb25fa2F4eG1ycjE',
              'gross_currency' => 'USD',
              'type' => 'chargeback',
            ]
          ]
        ],
      ],
    ];
  }

  /**
   * @dataProvider auditTestProvider
   */
  public function testParseFiles($path, $expectedMessages) {
    variable_set('braintree_audit_recon_files_dir', $path);

    $this->runAuditor();

    $this->assertMessages($expectedMessages);
  }

  public function testAlreadyRefundedTransactionIsSkipped(): void {
    variable_set('braintree_audit_recon_files_dir',  __DIR__ . '/data/Braintree/refundNoGatewayIDinCivi/');
    $expectedMessages = [
      'refund' => []
    ];

    $msg = [
      'currency' => 'USD',
      'date' => 1455825706,
      'gateway' => 'braintree',
      'gateway_txn_id' => 'dHJhbnNhY3Rpb25fa2F4eG1yfff',
      'gross' => 1.00,
    ];
    wmf_civicrm_mark_refund($this->ids['Contribution']['refund_test'], 'Refunded', TRUE, $msg['date'],
      $msg['gateway_txn_id'],
      $msg['currency'],
      $msg['gross']
    );
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

    $audit = new BraintreeAuditProcessor($options);
    $audit->run();
  }
}

<?php

use Civi\WMFAudit\BaseAuditTestCase;

/**
 * @group Braintree
 * @group WmfAudit
 */
class BraintreeAuditTest extends BaseAuditTestCase {

  public function setUp(): void {
    parent::setUp();
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
      'last_name' => 'Mouse',
      'payment_method' => 'paypal',
    ];
    $this->processDonationMessage($msg, FALSE);

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
    $this->processDonationMessage($msg, FALSE);

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
    $this->processDonationMessage($msg, FALSE);
    $contribution = $this->getContributionForMessage($msg);
    $this->ids['Contribution']['refund_test'] = $contribution['id'];
  }

  public function auditTestProvider(): array
  {
    return [
      'donation' => [
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
      'refund' => [
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
      'chargeback' => [
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
    \Civi::settings()->set('wmf_audit_directory_audit', $path);

    $this->runAuditor();

    $this->assertMessages($expectedMessages);
  }

  public function testAlreadyRefundedTransactionIsSkipped(): void {
    \Civi::settings()->set('wmf_audit_directory_audit', __DIR__ . '/data/Braintree/refundNoGatewayIDinCivi/');
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

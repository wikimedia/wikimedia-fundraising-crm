<?php

namespace Civi\WMFAudit;

use Civi\Api4\Contribution;
use Civi\Api4\ContributionTracking;
use SmashPig\Core\DataStores\QueueWrapper;

/**
 * @group Braintree
 * @group WmfAudit
 */
class BraintreeAuditTest extends BaseAuditTestCase {

  protected string $gateway = 'braintree';

  public function setUp(): void {
    parent::setUp();

    ContributionTracking::save(FALSE)
      ->addRecord([
        'id' => 35,
        'utm_medium' => 'braintree_audit',
        'utm_campaign' => 'braintree_audit',
      ])
      ->execute();

    $msg = [
      'gateway' => 'braintree',
      'date' => 1656383927,
      'gross' => '10.0',
      'contribution_tracking_id' => '34',
      'currency' => 'USD',
      'email' => 'donor@gmail.com',
      'gateway_txn_id' => 'dHJhbnNhY3Rpb25fMTYxZXdrMjk',
      'invoice_id' => '34.1',
      'phone' => NULL,
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
      'phone' => NULL,
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
      'phone' => NULL,
      'first_name' => 'f',
      'last_name' => 'Mouse',
      'payment_method' => 'paypal',
    ];
    $this->processDonationMessage($msg, FALSE);
    $contribution = $this->getContributionForMessage($msg);
    $this->ids['Contribution']['refund_test'] = $contribution['id'];
  }

  public function auditTestProvider(): array {
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
              'settled_currency' => 'USD',
              'settled_date' => NULL,
              'email' => 'donor@gmail.com',
              'gateway_txn_id' => 'dHJhbnNhY3Rpb25fa2szNmZ4Y3A',
              'audit_file_gateway' => 'braintree',
              'invoice_id' => '35.1',
              'phone' => 1234,
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
              'order_id' => '35.1',
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
          ],
        ],
      ],
      'chargeback' => [
        __DIR__ . '/data/Braintree/chargeback/',
        [
          "refund" => [
            [
              'gateway' => 'braintree',
              'date' => 1656381367,
              'gross' => '3.33',
              'gateway_parent_id' => 'dHJhbnNhY3Rpb25fa2F4eG1ycjE',
              'gateway_refund_id' => 'dHJhbnNhY3Rpb25fa2F4eG1ycjE',
              'gross_currency' => 'USD',
              'type' => 'chargeback',
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
          ],
        ],
      ],
    ];
  }

  /**
   * @dataProvider auditTestProvider
   * @throws \CRM_Core_Exception
   */
  public function testParseFiles(string $path, array $expectedMessages): void {
    \Civi::settings()->set('wmf_audit_directory_audit', $path);
    $this->runAuditor();
    $this->assertMessages($expectedMessages);

  }

  /**
   * @throws \CRM_Core_Exception
   */
  public function testPhoneIsSaved(): void {
     $this->runAuditBatch('donation', 'settlement_batch_report_2022-06-28.json', 'braintree');
     $contribution = Contribution::get(FALSE)
       ->addSelect('contact_id.phone_primary.phone')
       ->addSelect('contact_id.phone_primary.phone_data.phone_source')
       ->addSelect('contact_id.phone_primary.location_type_id')
       ->addWhere('contribution_extra.gateway_txn_id', '=', 'dHJhbnNhY3Rpb25fa2szNmZ4Y3A')
       ->execute()->first();
     $this->assertEquals('Venmo', $contribution['contact_id.phone_primary.phone_data.phone_source']);
  }

  /**
   * @param string $directory
   * @param string $fileName
   * @return array
   */
  public function getRows(string $directory, string $fileName): array {
    $this->setAuditDirectory($directory);
    // First let's have a process to create some TransactionLog entries.
    $file = $this->auditFileBaseDirectory . DIRECTORY_SEPARATOR . $directory . DIRECTORY_SEPARATOR . $this->gateway . DIRECTORY_SEPARATOR . 'incoming' . DIRECTORY_SEPARATOR . $fileName;
    try {
      $rows = json_decode(file_get_contents($file, 'r'), true, 512, JSON_THROW_ON_ERROR);
    }
    catch (\JsonException $e) {
      $this->fail('Failed to read json' . $file . ': ' . $e->getMessage());
    }
    return $rows;
  }

  public function createTransactionLog(array $row): void {
    $trackingID = $row['contribution_tracking_id'];
    $utmSource = "B2526_082914_esLA_m_p1_lg_twn_twin1_optIn0.no-LP.apple_amex";
    $this->ids['ContributionTracking'][] = ContributionTracking::save(FALSE)
      ->addRecord([
        'id' => $trackingID,
        'utm_source' => $utmSource,
      ])
      ->execute()->first()['id'];
    $gateway = $this->gateway;
    $gatewayTxnID = $row['gateway_txn_id'];
    $this->createTestEntity('TransactionLog', [
      'date' => $row['date'],
      'gateway' => $gateway,
      'gateway_account' => 'WikimediaDonations',
      'order_id' => $trackingID . '.1',
      'gateway_txn_id' => $gatewayTxnID,
      'message' => [
        "gateway_txn_id" => $gatewayTxnID,
        "response" => FALSE,
        "gateway_account" => "WikimediaDonations",
        "fee" => 0,
        "gross" => $row['gross'],
        "backend_processor" => $gateway,
        "backend_processor_txn_id" => NULL,
        "contribution_tracking_id" => $trackingID,
        "payment_orchestrator_reconciliation_id" => NULL,
        "currency" => $row['currency'],
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
        "date" => strtotime($row['date']),
      ]
    ], $gatewayTxnID);
  }

  /**
   * Test that a gravy transaction is not treated as missing if it exists.
   *
   * We can't help that it won't be created at this stage as the gravy ID
   * we receive in the audit file is not the same as that in the Pending table but
   * at least if it has been created we can check it is not considered to
   * be missing
   *
   * @return void
   * @throws \CRM_Core_Exception
   */
  public function testExistingGravyIsFound(): void {
    \Civi::settings()->set('wmf_audit_directory_audit', __DIR__ . '/data/Braintree/gravy_donation/');
    $this->createTestEntity('Contribution', [
      'trxn_id' => 'BRAINTREE dHJhbnNhY3Rpb25fa2szNmZ4Y3A',
      'contribution_extra.backend_processor' => 'braintree',
      'contribution_extra.backend_processor_txn_id' => 'dHJhbnNhY3Rpb25fa2szNmZ4Y3A',
      'contact_id' => $this->createIndividual(),
      'total_amount' => 5,
      'financial_type_id' => 1,
    ], 'gravy');
    $this->runAuditor();
    $queueName = 'donations';
    $this->assertQueueEmpty($queueName);
  }

  /**
   * @throws \CRM_Core_Exception
   */
  public function testAlreadyRefundedTransactionIsSkipped(): void {
    \Civi::settings()->set('wmf_audit_directory_audit', __DIR__ . '/data/Braintree/refundNoGatewayIDinCivi/');
    $expectedMessages = [
      'refund' => [],
    ];

    Contribution::update(FALSE)
      ->addValue('contribution_status_id:name', 'Refunded')
      ->addWhere('id', '=', $this->ids['Contribution']['refund_test'])
      ->execute();
    $this->runAuditor();
    $this->assertMessages($expectedMessages);
  }

}

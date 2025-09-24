<?php

namespace Civi\WMFAudit;

use Civi\Api4\Contribution;
use Civi\Api4\ContributionTracking;
use Civi\Api4\TransactionLog;

/**
 * @group Adyen
 * @group WmfAudit
 */
class AdyenAuditTest extends BaseAuditTestCase {
  protected $idForRefundTest;

  protected string $gateway = 'adyen';

  public function setUp(): void {
    parent::setUp();
    $msg = [
      'contribution_tracking_id' => 92598312,
      'currency' => 'USD',
      'date' => 1455825706,
      'email' => 'mouse@wikimedia.org',
      'gateway' => 'adyen',
      'gateway_txn_id' => '4522268860022701',
      'gross' => 1.00,
      'payment_method' => 'cc',
      'payment_submethod' => 'visa',
      'contribution_status_id' => 1,
    ];
    $this->processMessage($msg, 'Donation', 'test');

    $msg = [
      'contribution_tracking_id' => 92598318,
      'currency' => 'USD',
      'date' => 1443724034,
      'email' => 'mouse@wikimedia.org',
      'gateway' => 'adyen',
      'gateway_txn_id' => '4555568860022701',
      'gross' => 1.00,
      'payment_method' => 'cc',
      'payment_submethod' => 'visa',
    ];
    $this->processMessage($msg, 'Donation', 'test');

    // Fake refunded transaction
    $msg = [
      'contribution_tracking_id' => 1004,
      'invoice_id' => 1004.0,
      'currency' => 'USD',
      'date' => 1455825706,
      'email' => 'mouse@wikimedia.org',
      'gateway' => 'adyen',
      'gateway_txn_id' => '4522268860022703',
      'gross' => 1.00,
      'payment_method' => 'cc',
      'payment_submethod' => 'visa',
    ];
    $this->processMessage($msg, 'Donation', 'test');
    $this->ids['Contribution']['for_refund'] = $this->getContributionForMessage($msg)['id'];

    $contributionTrackingID = 82431234;
    $this->createContributionTracking([
      'id' => $contributionTrackingID,
      'utm_campaign' => 'adyen_audit',
    ]);
    $this->processContributionTrackingQueue();
    $this->ids['ContributionTracking'] = array_keys(
      (array) ContributionTracking::get(FALSE)
      ->addWhere('id', '>=', $contributionTrackingID)
      ->execute()->indexBy('id'));
    // Dunno where this pops up from but...
    $this->ids['ContributionTracking'][] = 1004;
  }

  public function tearDown(): void {
    TransactionLog::delete(FALSE)
      ->addWhere('gateway_txn_id', '=', '3f9c958c-ee57-4121-a79e-408946b27077')
      ->execute();
    $this->tearDownWMFEnvironment();
  }

  public function auditTestProvider(): array {
    return [
      'donations' => [
        __DIR__ . '/data/Adyen/donation_recur/',
        [
          'donations' => [
            [
              'utm_source' => 'B2021_0730_nlNL_m_p2_sm_twin2_optIn1.no-LP.rrtbt.rtbt_ideal',
              'utm_medium' => 'sitenotice',
              'utm_campaign' => 'C2017_nlNL_m_FR',
              'date' => 1487484651,
              'gateway' => 'adyen',
              'invoice_id' => '82431234.1',
              'gateway_txn_id' => '5364893193133131',
              'gross' => '2.35',
              'settled_gross' => '0.76',
              'settled_currency' => 'USD',
              'payment_method' => 'rtbt',
              'payment_submethod' => 'rtbt_ideal',
              'country' => 'NL',
              'first_name' => 'Bob',
              'gateway_account' => 'WikimediaDonations',
              'language' => 'nl',
              'last_name' => 'Mouse',
              'recurring' => '1',
              'recurring_payment_token' => '82431234.1',
              'user_ip' => '127.0.0.1',
              'opt_in' => '1',
              'order_id' => '82431234.1',
              'contribution_tracking_id' => '82431234',
              'processor_contact_id' => '82431234.1',
              'audit_file_gateway' => 'adyen',
              'settlement_batch_reference' => 'adyen_USD_2',
              'exchange_rate' => '1',
              'original_currency' => 'EUR',
              'currency' => 'EUR',
              'original_total_amount' => 2.35,
              'settled_fee_amount' => 0.24,
              'fee' => 0.24,
              'original_fee_amount' => 0.24,
              'original_net_amount' => 2.1100000000000003,
              'settled_net_amount' => 0.76,
              'settled_total_amount' => 1,
              'settled_date' => NULL,
            ],
          ],
        ],
      ],
      'donation_new' => [
        __DIR__ . '/data/Adyen/donation_new/',
        [
          'donations' => [
            [
              'contribution_tracking_id' => '43992337',
              'city' => 'asdf',
              'country' => 'US',
              'currency' => 'USD',
              'date' => 1487484651,
              'email' => 'mouse@wikimedia.org',
              'first_name' => 'asdf',
              'gateway' => 'adyen',
              'gateway_account' => 'TestMerchant',
              'gateway_txn_id' => '5364893193133131',
              'gross' => '1.00',
              'invoice_id' => '43992337.0',
              'language' => 'en',
              'last_name' => 'asdff',
              'order_id' => '43992337.0',
              'payment_method' => 'cc',
              'payment_submethod' => 'visa',
              'postal_code' => '11111',
              'recurring' => '',
              'state_province' => 'AK',
              'street_address' => 'asdf',
              'user_ip' => '77.177.177.77',
              'utm_campaign' => 'C13_en.wikipedia.org',
              'utm_medium' => 'sidebar',
              'utm_source' => '..cc',
              'settled_gross' => '0.76',
              'settled_currency' => 'USD',
              'tracking_date' => '2017-02-19 06:10:51',
              'audit_file_gateway' => 'adyen',
              'settlement_batch_reference' => 'adyen_USD_2',
              'exchange_rate' => '1',
              'original_currency' => 'USD',
              'original_total_amount' => 1,
              'settled_fee_amount' => 0.24,
              'original_fee_amount' => 0.24,
              'fee' => 0.24,
              'original_net_amount' => 0.76,
              'settled_net_amount' => 0.76,
              'settled_total_amount' => 1,
              'settled_date' => NULL,
            ],
          ],
        ],
      ],
      // The corresponding log file for the following is missing a
      // payment_submethod. We should take the submethod from the
      // audit parser.
      'donation_ideal' => [
        __DIR__ . '/data/Adyen/donation_ideal/',
        [
          'donations' => [
            [
              'contribution_tracking_id' => '80188432',
              'country' => 'NL',
              'currency' => 'EUR',
              'original_currency' => 'EUR',
              'date' => 1582488844,
              'email' => 'testy@wikimedia.org',
              'fee' => 0.25,
              'original_fee_amount' => 0.25,
              'first_name' => 'Testy',
              'gateway' => 'adyen',
              'gateway_account' => 'TestMerchant',
              'gateway_txn_id' => '1515876691993221',
              'gross' => '5.35',
              'original_total_amount' => 5.35,
              'invoice_id' => '80188432.1',
              'language' => 'nl',
              'last_name' => 'McTest',
              'order_id' => '80188432.1',
              'payment_method' => 'rtbt',
              'payment_submethod' => 'rtbt_ideal',
              'recurring' => '',
              'user_ip' => '123.45.67.89',
              'utm_campaign' => 'C1920_Email1',
              'utm_medium' => 'email',
              'utm_source' => 'sp1234567.default~default~JimmyQuote~default~control.rtbt.rtbt_ideal',
              'settled_gross' => '5.43',
              'settled_currency' => 'USD',
              'opt_in' => '0',
              'tracking_date' => '2020-02-23 20:14:04',
              'audit_file_gateway' => 'adyen',
              'settlement_batch_reference' => 'adyen_USD_630',
              'exchange_rate' => '1.0656568',
              'settled_fee_amount' => 0.27,
              'original_net_amount' => 5.1,
              'settled_net_amount' => 5.43,
              'settled_total_amount' => 5.7,
              'settled_date' => NULL,
            ],
          ],
        ],
      ],
      'refund' => [
        __DIR__ . '/data/Adyen/refund/',
        [
          'refund' => [
            [
              'date' => 1455128736,
              'gateway' => 'adyen',
              'gateway_parent_id' => '4522268860022701',
              'gateway_refund_id' => '4522268869855336',
              'gross' => '1.00',
              'gross_currency' => 'USD',
              'type' => 'refund',
              'settlement_batch_reference' => 'adyen_USD_3',
              'settled_total_amount' => -1,
              'settled_fee_amount' => 0,
              'settled_net_amount' => -1,
              'settled_currency' => 'USD',
              'original_currency' => 'USD',
              'settled_date' => null,
              'original_net_amount' => -1,
              'original_fee_amount' => 0,
              'original_total_amount' => -1,
            ],
          ],
        ],
      ],
      'chargeback' => [
        __DIR__ . '/data/Adyen/chargeback/',
        [
          'refund' => [
            [
              'date' => 1455128736,
              'gateway' => 'adyen',
              'gateway_parent_id' => '4555568860022701',
              'gateway_refund_id' => '4555568869855336',
              'gross' => '1.00',
              'gross_currency' => 'USD',
              'type' => 'chargeback',
              'settlement_batch_reference' => 'adyen_USD_3',
              'settled_total_amount' => -3,
              'settled_fee_amount' => -2,
              'settled_net_amount' => -1,
              'settled_currency' => 'USD',
              'original_currency' => 'USD',
              'settled_date' => null,
              'original_net_amount' => -1,
              'original_fee_amount' => -2,
              'original_total_amount' => -3,
            ],
          ],
        ],
      ],
    ];
  }

  public function auditErrorTestProvider(): array {
    return [
      [
        __DIR__ . '/data/Adyen/refund/',
        [
          'refund' => [
            [
              'date' => 1455128736,
              'gateway' => 'adyen',
              'gateway_parent_id' => '4522268860023102',
              'gateway_refund_id' => '4522268869854111',
              'gross' => '1.00',
              'gross_currency' => 'USD',
              'type' => 'refund',
            ],
          ],
        ],
      ],
    ];
  }

  /**
   * @dataProvider auditTestProvider
   * @throws \Exception
   */
  public function testParseFiles($path, $expectedMessages) {
    \Civi::settings()->set('wmf_audit_directory_audit', $path);

    $this->runAuditor();

    $this->assertMessages($expectedMessages);
  }

  /**
   * Test that gravy adyen chargebacks are handled if picked up through the adyen audit.
   *
   * The match is found based on the backend processor fields.
   * @return void
   */
  public function testGravyDonationSettled(): void {
    $contributionID = $this->createTestEntity('Contribution', [
      'contact_id' => $this->createIndividual(['email_primary.email' => 'mouse@wikimedia.org']),
      'total_amount' => 10.40,
      'contribution_extra.payment_orchestrator_reconciliation_id' => 'ABCDEFG',
      'receive_date' => '2025-07-24 05:55:55',
      'financial_type_id:name' => 'Recurring Gift - Cash',
      'payment_instrument_id:name' => 'Credit Card: Visa',
      'contribution_extra.original_currency' => 'ILS',
      'contribution_extra.original_amount' => 11.20,
      'contribution_extra.gateway' => 'gravy',
      'contribution_extra.gateway_txn_id' => 'MNOP',
      'contribution_extra.backend_processor' => 'adyen',
      'contribution_extra.backend_processor_txn_id' => 'FGH',
    ])['id'];
    \Civi::settings()->set('wmf_audit_directory_audit', __DIR__ . '/data/Adyen/donation_gravy/');
    $this->runAuditor();
    $this->processQueue('refund', 'Refund');
    $contribution = Contribution::get(FALSE)->addWhere('id', '>', $contributionID - 1)
      ->addSelect('contribution_extra.gateway_txn_id', 'contribution_extra.gateway', 'contribution_status_id:name', 'total_amount', 'fee_amount')
      ->execute()->single();
    $this->assertEquals('Completed', $contribution['contribution_status_id:name']);
    $this->assertEquals('gravy', $contribution['contribution_extra.gateway']);
    $this->assertEquals('MNOP', $contribution['contribution_extra.gateway_txn_id']);
  }

  /**
   * Test that gravy missing donations are handled.
   *
   * @return void
   */
  public function testGravyMissingDonationSettled(): void {
    $gravyTxnID = '3f9c958c-ee57-4121-a79e-408946b27077';
    $maxContributionTrackingID = (int) \CRM_Core_DAO::singleValueQuery('SELECT MAX(id) FROM civicrm_contribution_tracking');
    $trackingID = $this->ids['ContributionTracking'][] = ContributionTracking::save(FALSE)
      ->addRecord([
        'id' => $maxContributionTrackingID + 1,
      ])
      ->execute()->first()['id'];
    \Civi::settings()->set('wmf_audit_directory_audit', __DIR__ . '/data/Adyen/donation_gravy/');
    $this->createTestEntity('TransactionLog', [
      'date' => '2025-09-01 23:04:00',
      'gateway' => 'gravy',
      'gateway_account' => 'WikimediaDonations',
      'order_id' => $trackingID . '.1',
      'gateway_txn_id' => $gravyTxnID,
      'message' => [
        "gateway_txn_id" => $gravyTxnID,
        "response" => FALSE,
        "gateway_account" => "WikimediaDonations",
        "fee" => 0,
        "gross" => "56.75",
        "backend_processor" => "adyen",
        "backend_processor_txn_id" => "XYZ",
        "city" => "Wellington",
        "contribution_tracking_id" => $trackingID,
        "country" => "MX",
        "currency" => "MXN",
        "email" => "mouse@wikimedia.org",
        "first_name" => "Albert",
        "gateway" => "gravy",
        "language" => "es-419",
        "last_name" => "Mouse",
        "opt_in" => "0",
        "order_id" => $trackingID . '.1',
        "payment_method" => "apple",
        "payment_orchestrator_reconciliation_id" => "1w24hGOdCSFLtsgBQr2jKh",
        "payment_submethod" => "amex",
        "postal_code" => "20100",
        "recurring" => "",
        "state_province" => "Wellington",
        "street_address" => "1 The beehive",
        "user_ip" => "169.255.255.255",
        "utm_campaign" => "WMF_FR_C2526_esLA_m_0805",
        "utm_medium" => "sitenotice",
        "utm_source" => "B2526_082914_esLA_m_p1_lg_twn_twin1_optIn0.no-LP.apple_amex",
        "date" => 1756767840,
        "source_name" => "DonationInterface",
        "source_type" => "payments",
        "source_host" => "payments1007",
        "source_run_id" => 1219008,
        "source_version" => "973d0a66742ab85eeac413c4f7470fb208e33d29",
        "source_enqueued_time" => 1756767840
      ]
    ], 'auth');
    $this->createTestEntity('TransactionLog', [
      'date' => '2025-09-01 23:04:00',
      'gateway' => 'gravy',
      'gateway_account' => 'WikimediaDonations',
      'order_id' => $trackingID . '.1',
      'gateway_txn_id' => $gravyTxnID,
      'message' => [
        "gateway_txn_id" => $gravyTxnID,
        "response" => FALSE,
        "gateway_account" => "WikimediaDonations",
        "fee" => 0,
        "gross" => "56.75",
        "backend_processor" => "adyen",
        "backend_processor_txn_id" => "FGH",
        "city" => "Wellington",
        "contribution_tracking_id" => $trackingID,
        "country" => "MX",
        "currency" => "MXN",
        "email" => "mouse@wikimedia.org",
        "first_name" => "Albert",
        "gateway" => "gravy",
        "language" => "es-419",
        "last_name" => "Mouse",
        "opt_in" => "0",
        "order_id" => $trackingID . '.1',
        "payment_method" => "apple",
        "payment_orchestrator_reconciliation_id" => "1w24hGOdCSFLtsgBQr2jKh",
        "payment_submethod" => "amex",
        "postal_code" => "20100",
        "recurring" => "",
        "state_province" => "Wellington",
        "street_address" => "1 The beehive",
        "user_ip" => "169.255.255.255",
        "utm_campaign" => "WMF_FR_C2526_esLA_m_0805",
        "utm_medium" => "sitenotice",
        "utm_source" => "B2526_082914_esLA_m_p1_lg_twn_twin1_optIn0.no-LP.apple_amex",
        "date" => 1756767840,
        "source_name" => "DonationInterface",
        "source_type" => "payments",
        "source_host" => "payments1007",
        "source_run_id" => 1219008,
        "source_version" => "973d0a66742ab85eeac413c4f7470fb208e33d29",
        "source_enqueued_time" => 1756767840
      ]
    ], 'capture');
    $this->runAuditor();
    $this->processQueue('donations', 'Donation');
    $this->processContributionTrackingQueue();
    $contributionTracking = ContributionTracking::get(FALSE)
      ->addWhere('id', '=', $trackingID)
      ->execute()->first();
    $this->assertNotEmpty($contributionTracking['contribution_id']);
    $contribution = Contribution::get(FALSE)
      ->addWhere('id', '=', $contributionTracking['contribution_id'])
      ->execute()->single();
    $this->assertEquals('GRAVY 3f9c958c-ee57-4121-a79e-408946b27077', $contribution['trxn_id']);
  }

  /**
   * Test that gravy adyen chargebacks are handled if picked up through the adyen audit.
   *
   * The match is found based on the backend processor fields.
   * @return void
   */
  public function testGravyChargeback(): void {
    $contributionID = $this->createTestEntity('Contribution', [
      'contact_id' => $this->createIndividual(['email_primary.email' => 'mouse@wikimedia.org']),
      'total_amount' => 10.40,
      'fee_amount' => .2,
      'contribution_extra.payment_orchestrator_reconciliation_id' => 'ABCDEFG',
      'receive_date' => '2025-07-24 05:55:55',
      'financial_type_id:name' => 'Recurring Gift - Cash',
      'payment_instrument_id:name' => 'Credit Card: Visa',
      'contribution_extra.gateway' => 'gravy',
      'contribution_extra.gateway_txn_id' => 'MNOP',
      'contribution_extra.backend_processor' => 'adyen',
      'contribution_extra.backend_processor_txn_id' => 'FGH',
    ])['id'];
    \Civi::settings()->set('wmf_audit_directory_audit', __DIR__ . '/data/Adyen/chargeback_gravy/');
    $this->runAuditor();
    $this->processQueue('refund', 'Refund');
    $contribution = Contribution::get(FALSE)->addWhere('id', '>', $contributionID - 1)
      ->addSelect(
        'contribution_extra.gateway_txn_id',
        'contribution_extra.gateway',
        'contribution_status_id:name',
        'contribution_settlement.*',
        'total_amount',
        'fee_amount')
      ->execute()->single();
    $this->assertEquals('Chargeback', $contribution['contribution_status_id:name']);
    $this->assertEquals('gravy', $contribution['contribution_extra.gateway']);
    $this->assertEquals('MNOP', $contribution['contribution_extra.gateway_txn_id']);
    $this->assertEquals(-10.65, $contribution['contribution_settlement.settled_fee_reversal_amount']);
    $this->assertEquals(-21.05, $contribution['contribution_settlement.settled_reversal_amount']);
    $this->assertEquals('USD', $contribution['contribution_settlement.settlement_currency']);
    $this->assertEquals('adyen_USD_1122', $contribution['contribution_settlement.settlement_batch_reversal_reference']);

  }

  /**
   * @throws \CRM_Core_Exception
   * @throws \Civi\API\Exception\UnauthorizedException
   */
  public function testParseMissingContributionTracking(): void {
    // The contribution tracking record does not exist.
    $this->assertEmpty(ContributionTracking::get(FALSE)
      ->addWhere('id', '=', 43992337)
      ->execute());

    $this->testParseFiles(__DIR__ . '/data/Adyen/donation_new/',
      [
        'donations' => [
          [
            'contribution_tracking_id' => '43992337',
            'city' => 'asdf',
            'country' => 'US',
            'currency' => 'USD',
            'original_currency' => 'USD',
            'date' => 1487484651,
            'email' => 'mouse@wikimedia.org',
            'fee' => 0.24,
            'original_fee_amount' => 0.24,
            'first_name' => 'asdf',
            'gateway' => 'adyen',
            'gateway_account' => 'TestMerchant',
            'gateway_txn_id' => '5364893193133131',
            'gross' => '1.00',
            'original_total_amount' => 1,
            'invoice_id' => '43992337.0',
            'language' => 'en',
            'last_name' => 'asdff',
            'order_id' => '43992337.0',
            'payment_method' => 'cc',
            'payment_submethod' => 'visa',
            'postal_code' => '11111',
            'recurring' => '',
            'state_province' => 'AK',
            'street_address' => 'asdf',
            'user_ip' => '77.177.177.77',
            'utm_campaign' => 'C13_en.wikipedia.org',
            'utm_medium' => 'sidebar',
            'utm_source' => '..cc',
            'settled_gross' => '0.76',
            'settled_currency' => 'USD',
            'tracking_date' => '2017-02-19 06:10:51',
            'audit_file_gateway' => 'adyen',
            'settlement_batch_reference' => 'adyen_USD_2',
            'exchange_rate' => '1',
            'settled_fee_amount' => 0.24,
            'original_net_amount' => 0.76,
            'settled_net_amount' => 0.76,
            'settled_total_amount' => 1,
            'settled_date' => NULL,
          ],
        ],
      ]
    );
    $this->processContributionTrackingQueue();
    $tracking = ContributionTracking::get(FALSE)
      ->addWhere('id', '=', 43992337)
      ->execute()
      ->single();
    $this->assertEquals('audit', $tracking['utm_medium']);
    $this->assertEquals('audit..cc', $tracking['utm_source']);
    $this->assertEquals('en', $tracking['language']);
    $this->assertEquals('US', $tracking['country']);
    $this->assertEquals('2017-02-19 06:10:51', $tracking['tracking_date']);
  }

  /**
   * @throws \CRM_Core_Exception
   */
  public function testAlreadyRefundedTransactionIsSkipped(): void {
    \Civi::settings()->set('wmf_audit_directory_audit', __DIR__ . '/data/Adyen/refunded/');
    $expectedMessages = [
      'refund' => [],
    ];
    Contribution::update(FALSE)
      ->addValue('contribution_status_id:name', 'Refunded')
      ->addWhere('id', '=', $this->ids['Contribution']['for_refund'])
      ->execute();
    $result = $this->runAuditor();
    $this->assertEquals([
      'transaction_count' => 1,
      'settled_total_amount' => -1.0,
      'settled_fee_amount' => 0,
      'settled_net_amount' => -1.0,
      'settled_reversal_amount' => -1.0,
      'settled_donation_amount' => 0,
      'settlement_currency' => 'USD',
      'settlement_date' => '20250908',
      'settlement_batch_reference' => 'adyen_USD_3',
      'settlement_gateway' => 'adyen',
    ], $result->first());
    $this->assertMessages($expectedMessages);
  }

}

<?php

namespace Civi\WMFAudit;

use Civi\Api4\Batch;
use Civi\Api4\Contribution;
use Civi\Api4\ContributionTracking;
use Civi\Api4\TransactionLog;
use Civi\Api4\WMFAudit;
use League\Csv\Exception;
use League\Csv\Reader;
use SmashPig\Core\Helpers\Base62Helper;

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
    TransactionLog::delete(FALSE)
      ->addWhere('gateway_txn_id', 'LIKE', '%ABCD12345678910')
      ->execute();
    TransactionLog::delete(FALSE)
      ->addWhere('gateway_txn_id', 'LIKE', '%NEW123456789101')
      ->execute();
    TransactionLog::delete(FALSE)
      ->addWhere('gateway_txn_id', 'LIKE', '5364893193133131')
      ->execute();
    TransactionLog::delete(FALSE)
      ->addWhere('gateway_txn_id', 'LIKE', '35610b63-6667-4de7-94f9-ef8a26cf6131')
      ->execute();
    TransactionLog::delete(FALSE)
      ->addWhere('order_id', 'IN', ['1004.1', '12000.1'])
      ->execute();
    ContributionTracking::delete(FALSE)
      ->addWhere('id', '=', 43992337)
      ->execute();
    Contribution::delete(FALSE)
      ->addWhere('trxn_id', 'LIKE', 'ADYEN Transaction Fees%')
      ->execute();
    Batch::delete(FALSE)
      ->addWhere('name', 'LIKE', 'adyen_112%')
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
              'settlement_batch_reference' => 'adyen_2_USD',
              'exchange_rate' => '1',
              'original_currency' => 'EUR',
              'currency' => 'EUR',
              'original_total_amount' => 2.35,
              'settled_fee_amount' => -0.24,
              'fee' => 0.24,
              'original_fee_amount' => -0.24,
              'original_net_amount' => 2.11,
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
              'settlement_batch_reference' => 'adyen_2_USD',
              'exchange_rate' => '1',
              'original_currency' => 'USD',
              'original_total_amount' => 1,
              'settled_fee_amount' => -0.24,
              'original_fee_amount' => -0.24,
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
              'original_fee_amount' => -0.25,
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
              'settlement_batch_reference' => 'adyen_630_USD',
              'exchange_rate' => '1.0656568',
              'settled_fee_amount' => -0.27,
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
              'settlement_batch_reference' => 'adyen_3_USD',
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
              'settlement_batch_reference' => 'adyen_3_USD',
              'settled_total_amount' => -1,
              'settled_fee_amount' => -2,
              'settled_net_amount' => -3,
              'settled_currency' => 'USD',
              'original_currency' => 'USD',
              'settled_date' => null,
              'original_net_amount' => -3,
              'original_fee_amount' => -2,
              'original_total_amount' => -1,
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
    $this->processRefundQueue();
    $this->processSettleQueue();
    $contribution = Contribution::get(FALSE)->addWhere('id', '>', $contributionID - 1)
      ->addSelect('contribution_settlement.*', 'contribution_extra.gateway_txn_id', 'contribution_extra.gateway', 'contribution_status_id:name', 'total_amount', 'fee_amount')
      ->execute()->single();
    $this->assertEquals('Completed', $contribution['contribution_status_id:name']);
    $this->assertEquals('gravy', $contribution['contribution_extra.gateway']);
    $this->assertEquals('MNOP', $contribution['contribution_extra.gateway_txn_id']);
    $this->assertEquals('adyen_1122_USD', $contribution['contribution_settlement.settlement_batch_reference']);
    $this->assertEquals('USD', $contribution['contribution_settlement.settlement_currency']);
    $this->assertEquals('2025-08-06 16:59:59', $contribution['contribution_settlement.settlement_date']);
    $this->assertEquals(31.7, $contribution['contribution_settlement.settled_donation_amount']);
    $this->assertEquals(-10.65, $contribution['contribution_settlement.settled_fee_amount']);
  }

  /**
   * Test that a subsequent recurring can be built from the initial recur.
   *
   * @throws \CRM_Core_Exception
   */
  public function testSubsequentRecur(): void {
    $this->setAuditDirectory('donation_recur');
    $this->runAuditor();
    $this->processDonationsQueue();
    $this->setAuditDirectory('donation_recur_subsequent');
    $this->runAuditor();
    $this->processDonationsQueue();
    $contribution = Contribution::get(FALSE)
      ->addWhere('invoice_id', 'LIKE', '82431234.%|recur-%')
      ->addOrderBy('id', 'DESC')
    ->execute();
    $this->assertCount(2, $contribution);
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
    $this->processRefundQueue();
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
    $this->assertEquals(-10.40, $contribution['contribution_settlement.settled_reversal_amount']);
    $this->assertEquals('USD', $contribution['contribution_settlement.settlement_currency']);
    $this->assertEquals('adyen_1122_USD', $contribution['contribution_settlement.settlement_batch_reversal_reference']);
  }

  /**
   * Test a back including a donation which is settled, then charged back and then
   * the chargeback is reversed.
   *
   * The end goal is 2 donations - the original one is charged back and a new one is created
   * for the reversal.
   *
   *         Total Amount | Net Amount | Fee Amount | s*_donation_amount | s*_fee_amount | s*_reversal_amount | s*_reversal_fee_amount
   * Donation         5   | 4.89       | .11        | 5                  | -.11          | -5                 | -10.65
   * ChargeReversal   5   | 4.89       | .11        | 5                  | -.11          |
   *
   * @throws \CRM_Core_Exception|\League\Csv\Exception
   */
  public function testChargebackReversed(): void {
    $this->runAuditBatch('chargeback_reversal', 'settlement_detail_report_batch_1120.csv');
    // Run it three times - should do the first donation and chargeback reversal on the first run,
    // the chargeback on the second and nothing on the third.
    $this->runAuditBatch('chargeback_reversal', 'settlement_detail_report_batch_1120.csv');
    $this->runAuditBatch('chargeback_reversal', 'settlement_detail_report_batch_1120.csv');

    $chargedBackContribution = Contribution::get(FALSE)
      ->addWhere('contribution_settlement.settlement_batch_reversal_reference', '=', 'adyen_1120_USD')
      ->addSelect('contribution_settlement.*', 'total_amount', 'contribution_status_id:name', 'fee_amount')
      ->execute()->single();
    $this->assertEquals('Chargeback', $chargedBackContribution['contribution_status_id:name']);
    $this->assertEquals(-10.65, $chargedBackContribution['contribution_settlement.settled_fee_reversal_amount']);
    $this->assertEquals(-.11, $chargedBackContribution['contribution_settlement.settled_fee_amount']);
    $this->assertEquals(-5, $chargedBackContribution['contribution_settlement.settled_reversal_amount']);
    $this->assertEquals(5, $chargedBackContribution['contribution_settlement.settled_donation_amount']);
    $this->assertEquals('USD', $chargedBackContribution['contribution_settlement.settlement_currency']);
    $this->assertEquals('adyen_1120_USD', $chargedBackContribution['contribution_settlement.settlement_batch_reversal_reference']);
    $this->assertEquals('adyen_1120_USD', $chargedBackContribution['contribution_settlement.settlement_batch_reference']);

    $contribution = Contribution::get(FALSE)
      ->addWhere('contribution_status_id:name', '=', 'Completed')
      ->addWhere('contribution_settlement.settlement_batch_reference', '=', 'adyen_1120_USD')
      ->addSelect('trxn_id', 'contribution_settlement.*', 'total_amount', 'contribution_status_id:name', 'fee_amount')
      ->execute()->single();
    $this->assertEquals('CHARGEBACK_REVERSAL ADYEN 1234893193133131', $contribution['trxn_id']);

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
            'original_fee_amount' => -0.24,
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
            'settlement_batch_reference' => 'adyen_2_USD',
            'exchange_rate' => '1',
            'settled_fee_amount' => -0.24,
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
   * Test already refunded transaction as part of a small batch.
   *
   * The refunded transaction should have settlement data updated but not the fees/ amount.
   *
   * The csv has 3 other transactions and checks the totals
   * Refund:
   *  - total refunded to the donor $1.00 (settled_total_amount)
   *  - total fee (negative as it is a partial fee refund) -$0.11 (settled_fee_amount)
   *  - total charged to us -$0.89 (settled_net_amount)
   * Donation:
   *  - total paid by the donor $20.20 (settled_total_amount)
   *  - total fee $0.24 (settled_fee_amount)
   *  - total paid to us $19.96 (settled_net_amount)
   * Fee (not ingressed this patch but included in auditor totals watch this space)
   *   - total fee $1.80 (settled_fee_amount)
   *   - total paid by the fee contact $0 (settled_total_amount)
   *   - total deducted from our payout -1.80 (settled_net_amount)
   *
   * The sum of settled_net_amount is what we are paid
   * ie $19.96
   * less the charges (debits) against us ($.89 & $1.80) = $2.69
   * = 17.27
   *
   * The settled_fee_amount is the total paid to the gateway in fees on donations
   * (including fee-only rows but excluding reversals)
   * .24  -.11 + 1.80 = $1.93
   *
   * The settled_total_amount is the sum of the total amount paid by donors (donations only)
   * $19.20
   * - made up of settled_donation_amount ($19.20)
   * - settled_reversal_amount ($1.00) refunded to the donor
   *
   * $1.93 fees + $17.27 net_amount = $19.20 total_amount.
   *
   * @throws \CRM_Core_Exception
   */
  public function testAlreadyRefundedTransactionIsSkipped(): void {
    Contribution::update(FALSE)
      ->addValue('contribution_status_id:name', 'Refunded')
      ->addWhere('id', '=', $this->ids['Contribution']['for_refund'])
      ->execute();
    $expectedMessages = [
      'refund' => [],
    ];
    $directory = 'refunded';
    $file = 'settlement_detail_report_batch_4.csv';

    $result = $this->runAuditBatch($directory, $file);

    $contributions = Contribution::get(FALSE)
      ->addWhere('contribution_settlement.settlement_batch_reference', '=', 'adyen_1120_USD')
      ->addSelect('custom.*', '*')
      ->addOrderBy('id')
      ->execute();
    // The donation and the fee
    $this->assertCount(2, $contributions);
    $feeContribution = $contributions->first();
    $this->assertEquals(1.8, $feeContribution['fee_amount']);
    $this->assertEquals(-1.8, $feeContribution['net_amount']);
    $this->assertEquals(0, $feeContribution['total_amount']);
    $this->assertEquals(0, $feeContribution['contribution_settlement.settled_donation_amount']);
    $this->assertEquals(-1.8, $feeContribution['contribution_settlement.settled_fee_amount']);
    $donation = $contributions[1];
    $this->assertEquals(19.96, $donation['net_amount']);
    $this->assertEquals(.24, $donation['fee_amount']);
    $this->assertEquals(20.2, $donation['total_amount']);
    $this->assertEquals(20.2, $donation['contribution_settlement.settled_donation_amount']);
    $this->assertEquals(-.24, $donation['contribution_settlement.settled_fee_amount']);

    $contributions = Contribution::get(FALSE)
      ->addWhere('contribution_settlement.settlement_batch_reversal_reference', '=', 'adyen_1120_USD')
      ->addSelect('contribution_settlement.*', 'total_amount', 'net_amount', 'fee_amount')
      ->addOrderBy('id')
      ->execute();
    // The refund.
    $this->assertCount(1, $contributions, print_r($contributions, TRUE));
    $refundContribution = $contributions->first();
    $this->assertEquals(.11, $refundContribution['contribution_settlement.settled_fee_reversal_amount']);
    $this->assertEquals(-1, $refundContribution['contribution_settlement.settled_reversal_amount']);
    // These are unchanged from the original donation.
    $this->assertEquals(1, $refundContribution['net_amount']);
    $this->assertEquals(0, $refundContribution['fee_amount']);
    $this->assertEquals(1, $refundContribution['total_amount']);

    // Batch contains one refund and one donation.
    $this->assertEquals([
      'transaction_count' => 3,
      'settled_total_amount' => 19.2,
      'settled_fee_amount' => -1.93,
      'settled_net_amount' => 17.27,
      'settled_reversal_amount' => -1.0,
      'settled_donation_amount' => 20.20,
      'settlement_currency' => 'USD',
      'settlement_date' => '20250912',
      'settlement_batch_reference' => 'adyen_1120_USD',
      'settlement_gateway' => 'adyen',
      'status_id:name' => 'total_verified',
    ], $result['batch']->first());
    $this->assertMessages($expectedMessages);

    // Run the batch again to confirm it doesn't fail on the second go at the fees
    $this->runAuditBatch($directory, $file);

    $contributions = Contribution::get(FALSE)
      ->addWhere('contribution_settlement.settlement_batch_reference', '=', 'adyen_1120_USD')
      ->addSelect('custom.*', '*')
      ->addOrderBy('id')
      ->execute();
    // The donation and the fee
    $this->assertCount(2, $contributions);
  }

  public function createTransactionLog(array $row): void {
    if (empty($row['Creation Date']) || in_array($row['Type'], ['Fee', 'MerchantPayout'], TRUE)) {
      // Fee row.
      return;
    }
    $orderID = $row['Merchant Reference'];
    $trackingID = explode('.', $orderID)[0];
    $isGravy = !is_numeric($trackingID);
    if ($isGravy) {
      $trackingID = 1 + ((int) \CRM_Core_DAO::singleValueQuery('SELECT MAX(id) FROM civicrm_contribution_tracking'));
    }
    $utmSource = "B2526_082914_esLA_m_p1_lg_twn_twin1_optIn0.no-LP.apple_amex";
    $this->ids['ContributionTracking'][] = ContributionTracking::save(FALSE)
      ->addRecord([
        'id' => $trackingID,
        'utm_source' => $utmSource,
      ])
      ->execute()->first()['id'];
    $gateway = $isGravy ? 'gravy' : $this->gateway;
    $gatewayTxnID = $gateway === $this->gateway ? $row['Psp Reference'] : Base62Helper::toUuid($row['Merchant Reference']);
    $this->createTestEntity('TransactionLog', [
      'date' => $row['Creation Date'],
      'gateway' => $gateway,
      'gateway_account' => 'WikimediaDonations',
      'order_id' => $trackingID . '.1',
      'gateway_txn_id' => $gatewayTxnID,
      'message' => [
        "gateway_txn_id" => $gatewayTxnID,
        "response" => FALSE,
        "gateway_account" => "WikimediaDonations",
        "fee" => 0,
        "gross" => $row['Gross Debit (GC)'],
        "backend_processor" => $isGravy ? "adyen" : NULL,
        "backend_processor_txn_id" => $isGravy ? $row['Psp Reference'] : NULL,
        "contribution_tracking_id" => $trackingID,
        "payment_orchestrator_reconciliation_id" => $isGravy ? $row['Merchant Reference'] : NULL,
        "currency" => $row['Gross Currency'],
        "order_id" => $trackingID . '.1',
        "payment_method" => "apple",
        "payment_submethod" => "amex",
        "email" => $gatewayTxnID . "@wikimedia.org",
        "first_name" => $gatewayTxnID,
        "gateway" => $isGravy ? 'gravy' : 'adyen',
        "last_name" => "Mouse",
        "user_ip" => "169.255.255.255",
        "utm_campaign" => "WMF_FR_C2526_esLA_m_0805",
        "utm_medium" => "sitenotice",
        "utm_source" => $utmSource,
        "date" => strtotime($row['Creation Date']),
      ]
    ], $gatewayTxnID);
  }

  /**
   * @param string $directory
   * @param string $fileName
   * @return array
   */
  public function prepareForAuditProcessing(string $directory, string $fileName): array {
    $this->setAuditDirectory($directory);
    // First let's have a process to create some TransactionLog entries.
    $file = $this->auditFileBaseDirectory . DIRECTORY_SEPARATOR . $directory . DIRECTORY_SEPARATOR . $this->gateway . DIRECTORY_SEPARATOR . 'incoming' . DIRECTORY_SEPARATOR . $fileName;
    try {
      $csv = Reader::createFromPath($file, 'r');
      $csv->setHeaderOffset(0);
    }
    catch (Exception $e) {
      $this->fail('Failed to read csv' . $file . ': ' . $e->getMessage());
    }
    foreach ($csv as $row) {
      $this->createTransactionLog($row);
    }
    return $row;
  }

  /**
   * @param string $directory
   * @param string $fileName
   * @param string $batchName
   * @return array
   *
   * @throws \CRM_Core_Exception
   */
  public function runAuditBatch(string $directory, string $fileName, string $batchName = ''): array {
    $this->prepareForAuditProcessing($directory, $fileName);

    $this->runAuditor();
    $this->processDonationsQueue();
    $this->processContributionTrackingQueue();
    $this->processRefundQueue();
    $this->processSettleQueue();

    $this->processContributionTrackingQueue();
    $auditResult['batch'] = $this->runAuditor();
    if ($batchName) {
      $auditResult['validate'] = WMFAudit::generateBatch(FALSE)
        ->setBatchPrefix($batchName)
        ->setIsOutputCsv(TRUE)
        ->execute();

      foreach ($auditResult['validate'] as $row) {
        $this->assertEquals(0, array_sum($row['validation']), print_r($row, TRUE));
      }
    }
    return (array) $auditResult;
  }

  public function testAdyenSettlementBatch(): void {
    $fileName = 'settlement_detail_report_batch_1128.csv';
    $directory = 'batch_1';
    // Run it once to get the main donation & then again to process the refund & chargeback.
    $this->runAuditBatch($directory, $fileName);
    $validate = $this->runAuditBatch($directory, $fileName, 'adyen_1128')['validate'];
    $this->assertCount(2, $validate);
    //    	Total debits	  Total credits	  Payout
    // USD	234.79	         196.97	       37.82
    // EUR	47.98	           19.47	       28.51
    // US batch breaks down as
    // Settled donation amount = 237.36 - 4 Donations totalling 237.36 less 2.57 fees = net 234.79
    // Less Settled Reversal amount = -107.65 credited to donors 160.97 credited from our account less 53.32 fees
    // Less Settled fee amount amount - -55.89 in line fees + $36 in fee rows
    // = $37.82 net amount
    $this->runAuditBatch('batch_2', 'settlement_detail_report_batch_1129.csv', 'adyen_1129');
  }

  public function testBatchMoreThanOneFile(): void {
    $this->prepareForAuditProcessing('batch_2', 'settlement_detail_report_batch_1129.csv');
    $result = $this->runAuditor();
    $this->assertEquals(37.82, $result->first()['settled_net_amount']);
    $this->assertEquals('USD', $result->first()['settlement_currency']);
    $this->assertEquals(14, $result->first()['transaction_count']);
  }

}

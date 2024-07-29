<?php

namespace Civi\WMFAudit;

use Civi\Api4\Contribution;
use Civi\Api4\ContributionTracking;
use Civi\WMFException\WMFException;

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

    $this->createContributionTracking([
      'id' => 82431234,
      'utm_campaign' => 'adyen_audit',
    ]);
    $this->ids['ContributionTracking'][] = 43992337;
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
              'currency' => 'EUR',
              'gross' => '2.35',
              'fee' => 0.24,
              'settled_gross' => '0.76',
              'settled_currency' => 'USD',
              'settled_fee' => 0.24,
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
              'fee' => 0.24,
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
              'settled_fee' => 0.24,
            ],
          ],
        ],
      ],
      // The corresponding log file for the following is missing a
      // payment_submethod. We should take the submethod from the
      // audit parser.
      [
        __DIR__ . '/data/Adyen/donation_ideal/',
        [
          'donations' => [
            [
              'contribution_tracking_id' => '80188432',
              'country' => 'NL',
              'currency' => 'EUR',
              'date' => 1582488844,
              'email' => 'testy@wikimedia.org',
              'fee' => 0.25,
              'first_name' => 'Testy',
              'gateway' => 'adyen',
              'gateway_account' => 'TestMerchant',
              'gateway_txn_id' => '1515876691993221',
              'gross' => '5.35',
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
              'settled_fee' => 0.27,
              'opt_in' => '0',
            ],
          ],
        ],
      ],
      [
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
            ],
          ],
        ],
      ],
      [
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
            'date' => 1487484651,
            'email' => 'mouse@wikimedia.org',
            'fee' => 0.24,
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
            'settled_fee' => 0.24,
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
    $this->runAuditor();
    $this->assertMessages($expectedMessages);
  }

}

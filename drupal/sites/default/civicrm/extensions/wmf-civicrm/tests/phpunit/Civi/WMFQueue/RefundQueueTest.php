<?php

namespace Civi\WMFQueue;

use Civi\Api4\Activity;
use Civi\Api4\Contact;
use Civi\Api4\Contribution;
use Civi\Api4\ContributionRecur;
use Civi\Api4\EntityFinancialTrxn;
use Civi\WMFException\WMFException;
use SmashPig\Core\DataStores\QueueWrapper;
use SmashPig\CrmLink\Messages\SourceFields;
use Civi\WMFHelper\ContributionRecur as RecurHelper;
use SmashPig\PaymentProviders\Responses\CancelSubscriptionResponse;
use SmashPig\Tests\TestingContext;
use SmashPig\Tests\TestingProviderConfiguration;

/**
 * @group Queue2Civicrm
 */
class RefundQueueTest extends BaseQueueTestCase {

  private $paypalProvider;

  public function setUp() : void {
    parent::setUp();

    $ctx = TestingContext::get();

    $providerConfig = TestingProviderConfiguration::createForProvider(
      'paypal', $ctx->getGlobalConfiguration()
    );

    $this->paypalProvider = $this->getMockBuilder(
      'SmashPig\PaymentProviders\Paypal\PaymentProvider'
    )->disableOriginalConstructor()->getMock();

    $providerConfig->overrideObjectInstance('payment-provider/paypal', $this->paypalProvider);

    $ctx->providerConfigurationOverride = $providerConfig;
  }

  protected string $queueName = 'refund';

  protected string $queueConsumer = 'Refund';

  public function testRefund(): void {
    $donation_message = $this->getDonationMessage([], TRUE, ['USD' => 1, '*' => 3]);
    $refund_message = $this->getRefundMessage(['gateway_parent_id' => $donation_message['gateway_txn_id']]);

    $this->processMessage($donation_message, 'Donation', 'test');
    $this->assertOneContributionExistsForMessage($donation_message);

    $this->processMessage($refund_message);
    $this->assertMessageContributionStatus($donation_message, 'Chargeback');
  }

  public function testRefundNoPredecessor(): void {
    $this->expectException(WMFException::class);
    $this->expectExceptionCode(WMFException::MISSING_PREDECESSOR);
    $this->processMessageWithoutQueuing($this->getRefundMessage());
  }

  public function testRefundEmptyRequiredField(): void {
    $this->expectException(WMFException::class);
    $this->expectExceptionCode(WMFException::CIVI_REQ_FIELD);
    $refund_message = $this->getRefundMessage(['gross' => '']);
    $this->processMessageWithoutQueuing($refund_message);
  }

  /**
   * Test refunding a mismatched amount.
   *
   * Note that we were checking against an exception - but it turned out the
   * exception could be thrown in this fn $this->queueConsumer->processMessage
   * if the exchange rate does not exist - which is not what we are testing
   * for.
   *
   * @throws \CRM_Core_Exception
   */
  public function testRefundMismatched(): void {
    $donation_message = $this->getDonationMessage(['gateway' => 'test_gateway']);

    $this->processMessage($donation_message, 'Donation', 'test');
    $this->assertOneContributionExistsForMessage($donation_message);

    $this->processMessage($this->getRefundMessage([
      'gross' => $donation_message['original_gross'] + 1,
      'gateway_parent_id' => $donation_message['gateway_txn_id'],
      'gateway' => 'test_gateway',
    ]));
    $contribution = $this->getContributionForMessage($donation_message);
    $this->assertEquals(
      'Chargeback',
      $contribution['contribution_status_id:name']
    );
    $adjustmentContribution = Contribution::get(FALSE)->addWhere('contact_id', '=', $contribution['contact_id'])
      ->addWhere('id', '<>', $contribution['id'])
      ->execute()->single();

    $this->assertEquals(-.5, $adjustmentContribution['total_amount']);
  }

  /**
   * Refunds raised by PayPal do not indicate whether the initial
   * payment was taken using the PayPal express checkout (paypal_ec) integration or
   * the legacy PayPal integration (PayPal). We try to work this out by checking for
   * the presence of specific values in messages sent over, but it appears this
   * isn't watertight as we've seen refunds failing due to incorrect mappings
   * on some occasions.
   *
   * To mitigate this we now fall back to the alternative gateway if no match is
   * found for the gateway supplied.
   */
  public function testPaypalExpressFallback(): void {
    // add a paypal_ec donation
    $donation_message = $this->getDonationMessage(['gateway' => 'paypal_ec']);
    $this->processMessage($donation_message, 'Donation', 'test');

    // simulate a mis-mapped PayPal legacy refund
    $this->processMessage($this->getRefundMessage(
      [
        'gateway' => 'paypal',
        'gateway_parent_id' => $donation_message['gateway_txn_id'],
        'gross' => $donation_message['original_gross'] + 1,
      ]
    ));
    $this->assertOneContributionExistsForMessage($donation_message);
    $this->assertMessageContributionStatus($donation_message, 'Chargeback');
  }

  /**
   * @throws \CRM_Core_Exception
   */
  public function testPaypalExpressCancelRecurringOnChargeback(): void {
    $signupMessage = $this->getRecurringSignupMessage();
    $this->processMessage($signupMessage, 'Recurring', 'recurring');
    $recurRecord = ContributionRecur::get(FALSE)
      ->addWhere('trxn_id', '=', $signupMessage['subscr_id'])
      ->execute()->single();
    $this->ids['ContributionRecur'][] = $recurRecord['id'];
    $donationMessage = $this->getDonationMessage([
      'gateway' => 'paypal',
      'contribution_recur_id' => $recurRecord['id'],
    ], TRUE, []);
    $this->processMessage($donationMessage, 'Donation', 'test');

    $this->paypalProvider->expects($this->once())
      ->method('cancelSubscription')
      ->willReturn(
       (new CancelSubscriptionResponse())->setRawResponse([])
      );

    // simulate a mis-mapped PayPal legacy refund
    $this->processMessage($this->getRefundMessage(
    [
      'gateway' => 'paypal',
      'gateway_parent_id' => $donationMessage['gateway_txn_id'],
      'gross' => $donationMessage['original_gross'] + 1,
    ]
    ));
    $this->assertOneContributionExistsForMessage($donationMessage);
    $this->assertMessageContributionStatus($donationMessage, 'Chargeback');
    $this->processQueue('recurring', 'Recurring');
    $recurRecord = ContributionRecur::get(FALSE)
      ->addWhere('trxn_id', '=', $signupMessage['subscr_id'])
      ->addSelect('*', 'contribution_status_id:name')
      ->execute()->single();

    $this->assertEquals('Cancelled', $recurRecord['contribution_status_id:name']);
  }

  /**
   * @see testPaypalExpressFallback
   */
  public function testPaypalLegacyFallback(): void {
    // add a paypal legacy donation
    $donation_message = $this->getDonationMessage(['gateway' => 'paypal']);
    $this->processMessage($donation_message, 'Donation', 'test');

    // simulate a mis-mapped paypal_ec refund
    $this->processMessage($this->getRefundMessage(
      [
        'gateway' => 'paypal_ec',
        'gateway_parent_id' => $donation_message['gateway_txn_id'],
        'gross' => $donation_message['original_gross'] + 1,
      ]
    ));
    $this->assertOneContributionExistsForMessage($donation_message);
    $this->assertMessageContributionStatus($donation_message, 'Chargeback');
  }

  /**
   * Ensure that Civi core code (CRM_Contribute_BAO_ContributionRecur::updateOnTemplateUpdated)
   * does not edit contribution_recur rows to match the currency and amount of an associated
   * contribution when the contribution is edited.
   *
   * We used to implement a hook to run interference on core
   * behaviour but the core behaviour is now fixed, so we are testing that.
   *
   * @throws \CRM_Core_Exception
   */
  public function testRefundDoesNotChangeRecurCurrency(): void {
    $initialDonation = [
      'gateway_txn_id' => 'TEST-1234',
      'contribution_tracking_id' => 13,
      'utm_source' => '..rcc',
      'language' => 'en',
      'email' => 'jwales@example.com',
      'first_name' => 'Jimmy',
      'last_name' => 'Mouse',
      'country' => 'US',
      'gateway' => 'adyen',
      'order_id' => '13.1',
      'recurring' => '1',
      'payment_method' => 'cc',
      'payment_submethod' => 'discover',
      'currency' => 'EUR',
      'gross' => '10.00',
      'user_ip' => '172.18.0.1',
      'recurring_payment_token' => 'DB44P92T43M84H82',
      'processor_contact_id' => '13.1',
      'date' => 1669082766,
      'financial_type_id' => RecurHelper::getFinancialTypeForFirstContribution(),
    ];
    $this->setExchangeRates(1669082766, [
      'USD' => 1,
      'EUR' => 1.1,
    ]);

    $this->processMessage($initialDonation, 'Donation', 'test');

    // Import will convert the contribution to USD but leave the contribution_recur as EUR
    $contribution = $this->getContributionForMessage($initialDonation);
    $this->assertEquals('USD', $contribution['currency']);
    $this->assertEquals('EUR', $contribution['contribution_recur_id.currency']);
    $refundMessage = [
      'type' => 'refund',
      'date' => 1669082866,
      'gateway' => 'adyen',
      'gateway_parent_id' => 'TEST-1234',
      'gateway_refund_id' => 'TEST-1234',
      'gross' => 10.00,
      'gross_currency' => 'EUR',
    ];
    $this->processMessage($refundMessage);
    // Make sure that the recurring record's currency is unchanged
    $newRecurRecord = ContributionRecur::get(FALSE)->addWhere('id', '=', $contribution['contribution_recur_id'])->execute()->single();
    $this->assertEquals('EUR', $newRecurRecord['currency']);
  }

  /**
   * Test refunding a mismatched refund currency.
   */
  public function testRefundMismatchedRefundCurrency(): void {
    $donation_message = $this->getDonationMessage(['gateway' => 'test_gateway']);
    $this->processMessage($donation_message, 'Donation', 'test');
    $refund_message = $this->getRefundMessage([
      'gateway' => 'test_gateway',
      'gateway_parent_id' => $donation_message['gateway_txn_id'],
      'gross' => $donation_message['original_gross'] * 0.5,
      'gross_currency' => 'USD',
    ]);

    $this->processMessage($refund_message);
    $this->assertOneContributionExistsForMessage($donation_message);
    $this->assertMessageContributionStatus($donation_message, 'Chargeback');
  }

  /**
   * @throws \SmashPig\Core\ConfigurationKeyException
   * @throws \CRM_Core_Exception
   * @throws \PHPQueue\Exception\JobNotFoundException
   */
  public function testChargebackRecurring(): void {
    $signupMessage = $this->getRecurringSignupMessage();
    $this->processMessage($signupMessage, 'Recurring', 'recurring');
    $recurRecord = ContributionRecur::get(FALSE)
      ->addWhere('trxn_id', '=', $signupMessage['subscr_id'])
      ->execute()->single();
    $this->ids['ContributionRecur'][] = $recurRecord['id'];
    $donationMessage = $this->getDonationMessage([
      'gateway' => 'test_gateway',
      'contribution_recur_id' => $recurRecord['id'],
    ], TRUE, []);
    $this->processMessage($donationMessage, 'Donation', 'test');

    $refundMessage = $this->getRefundMessage([
      'gateway' => 'test_gateway',
      'gateway_parent_id' => $donationMessage['gateway_txn_id'],
      'type' => 'chargeback',
      'gross' => $donationMessage['original_gross'],
      'gross_currency' => $donationMessage['original_currency'],
    ]);
    $this->processMessage($refundMessage);
    $cancelMessage = QueueWrapper::getQueue('recurring')->pop();
    SourceFields::removeFromMessage($cancelMessage);
    $this->assertArrayHasKey('payment_instrument_id', $cancelMessage);
    unset($cancelMessage['payment_instrument_id']);
    $this->assertEquals(
      [
        'gateway' => 'test_gateway',
        'contribution_recur_id' => $recurRecord['id'],
        'txn_type' => 'subscr_cancel',
        'cancel_reason' => 'Automatically cancelling because we received a chargeback',
      ],
      $cancelMessage
    );
  }

  /**
   * Test a retryable chargeback
   * Will not cancel the recurring
   */
  public function testChargebackRetryableReason(): void {
    $signupMessage = $this->getRecurringSignupMessage();
    $this->processMessage($signupMessage, 'Recurring', 'recurring');
    $recurRecord = ContributionRecur::get(FALSE)
      ->addWhere('trxn_id', '=', $signupMessage['subscr_id'])
      ->execute()->single();
    $this->ids['ContributionRecur'][] = $recurRecord['id'];
    $donationMessage = $this->getDonationMessage([
      'gateway' => 'test_gateway',
      'contribution_recur_id' => $recurRecord['id'],
    ], TRUE, []);
    $this->processMessage($donationMessage, 'Donation', 'test');

    $tempIssueReason = 'AM04:InsufficientFunds';
    $refundMessage = $this->getRefundMessage([
      'gateway' => 'test_gateway',
      'gateway_parent_id' => $donationMessage['gateway_txn_id'],
      'type' => 'chargeback',
      'gross' => $donationMessage['original_gross'],
      'gross_currency' => $donationMessage['original_currency'],
      'reason' => $tempIssueReason,
    ]);
    $this->processMessage($refundMessage, 'Refund','refund');
    // test add reason to activity
    $activity = Activity::get(FALSE)
      ->addWhere('source_record_id', '=', $donationMessage['gateway_txn_id'])
      ->addWhere('subject', '=', 'Refund Reason')
      ->addSelect('details')
      ->execute()->first();
    $this->assertEquals('Chargeback reason: ' . $tempIssueReason, $activity['details']);
    // chargeback reason is due to temporary issue, so we should not cancel the recurring
    $cancelMessage = QueueWrapper::getQueue('recurring')->pop();
    $this->assertNull($cancelMessage);
  }

  /**
   * Generic testing of refund handling.
   *
   * @throws \CRM_Core_Exception
   */
  public function testMarkRefund() {
    $this->setupOriginalContribution();
    $message = [
      'gateway_parent_id' => 'E-I-E-I-O',
      'gross_currency' => 'EUR',
      'gross' => 1.23,
      'date' => '2015-09-09',
      'gateway' => 'test_gateway',
      'gateway_refund_id' => 'my_special_ref',
      'type' => 'refund',
    ];
    $this->processMessage($message, 'Refund', 'refund');

    $contribution = $this->getContribution('original');

    $this->assertEquals('Refunded', $contribution['contribution_status_id:name'], 'Contribution not refunded');

    $financialTransactions = EntityFinancialTrxn::get(FALSE)
      ->addWhere('entity_table', '=', 'civicrm_contribution')
      ->addWhere('entity_id', '=', $this->ids['Contribution']['original'])
      ->addSelect('financial_trxn_id.*')
      ->execute();

    $this->assertCount(2, $financialTransactions);

    $this->assertEquals('TEST_GATEWAY E-I-E-I-O', $financialTransactions[0]['financial_trxn_id.trxn_id']);
    $this->assertEquals(strtotime('2015-09-09'), strtotime($financialTransactions[1]['financial_trxn_id.trxn_date']));
    $this->assertEquals('my_special_ref', $financialTransactions[1]['financial_trxn_id.trxn_id']);

    // With no valid donations we wind up with null not zero as no rows are selected
    // in the calculation query.
    // This seems acceptable. we would probably need a tricky union or extra IF to
    // force to NULL. Field defaults are ignored in INSERT ON DUPLICATE UPDATE,
    // seems an OK sacrifice. If one valid donation (in any year) exists we
    // will get zeros in other years so only non-donors will have NULL values.
    // not quite sure why some are zeros not null?
    $this->assertContactValues($contribution['contact_id'], [
      'wmf_donor.lifetime_usd_total' => NULL,
      'wmf_donor.last_donation_date' => NULL,
      'wmf_donor.last_donation_amount' => 0.00,
      'wmf_donor.last_donation_usd' => 0.00,
      'wmf_donor.' . $this->getCurrentFinancialYearTotalFieldName() => NULL,
    ]);
  }

  /**
   * Check that marking a contribution as refunded updates WMF Donor data.
   */
  public function testMarkRefundCheckWMFDonorData(): void {
    $this->setupOriginalContribution();
    $lastYear = date('Y', strtotime('-1 year'));
    $thisYear = date('Y', strtotime('+0 year'));
    $this->createTestEntity('Contact', ['contact_type' => 'Individual', 'first_name' => 'Maisy', 'last_name' => 'Mouse'], 'maisy');
    $this->createTestEntity('Contribution', [
      'contact_id' => $this->ids['Contact']['maisy'],
      'financial_type_id:name' => 'Cash',
      'total_amount' => 50,
      'source' => 'USD 50',
      'receive_date' => "$lastYear-11-01",
      'contribution_extra.gateway' => 'adyen',
      'contribution_extra.gateway_txn_id' => 345,
    ]);
    // Create an additional negative contribution. This is how they were prior to Feb 2016.
    // We want to check it is ignored for the purpose of determining the most recent donation,
    // although it should contribute to the lifetime total.
    $this->createTestEntity('Contribution', [
      'contact_id' => $this->ids['Contact']['maisy'],
      'financial_type_id:name' => 'Cash',
      'total_amount' => -10,
      'contribution_source' => 'USD -10',
      'receive_date' => "$lastYear-12-01",
    ]);

    $this->processMessage([
      'gateway_parent_id' => 345,
      'gateway' => 'adyen',
      'gateway_txn_id' => 'my_special_ref',
      'gross' => 10,
      'date' => "$lastYear-09-09",
      'type' => 'refund',
    ], 'Refund', 'refund');

    $this->assertContactValues($this->ids['Contact']['maisy'], [
      'wmf_donor.lifetime_usd_total' => 40,
      'wmf_donor.last_donation_date' => "$lastYear-11-01 00:00:00",
      'wmf_donor.last_donation_amount' => 50,
      'wmf_donor.last_donation_usd' => 50,
      'wmf_donor.last_donation_currency' => 'USD',
      "wmf_donor.total_$lastYear" => 40,
      'wmf_donor.number_donations'  => 1,
      "wmf_donor.total_{$lastYear}_{$thisYear}" => 40,
    ]);
  }

  /**
   * Asset the specified fields match those on the given contact.
   *
   * @param int $contactID
   * @param array $expected
   */
  protected function assertContactValues(int $contactID, array $expected) {
    try {
      $contact = Contact::get(FALSE)->setSelect(
        array_keys($expected)
      )->addWhere('id', '=', $contactID)->execute()->first();
    }
    catch (\CRM_Core_Exception $e) {
      $this->fail($e->getMessage());
    }

    foreach ($expected as $key => $value) {
      $this->assertEquals($value, $contact[$key], "wrong value for $key");
    }
  }

  /**
   * Make a refund with type set to "chargeback"
   *
   * @throws \CRM_Core_Exception
   */
  public function testMarkRefundWithType(): void {
    $this->setupOriginalContribution();
    $this->processMessage([
      'gateway_parent_id' => 'E-I-E-I-O',
      'gateway' => 'test_gateway',
      'gateway_txn_id' => 'my_special_ref',
      'gross' => 10,
      'gross_currency' => 'USD',
      'date' => date('Ymd'),
      'type' => 'chargeback',
    ], 'Refund', 'refund');

    $contribution = Contribution::get(FALSE)
      ->addWhere('id', '=', $this->ids['Contribution']['original'])
      ->addSelect('contribution_status_id:name')
      ->execute()->single();

    $this->assertEquals('Chargeback', $contribution['contribution_status_id:name'],
      'Refund contribution has correct type');
  }

  /**
   * Make a refund for less than the original amount.
   *
   * The original contribution is refunded & a new contribution is created to represent
   * the balance (.25 EUR or 13 cents) so the contact appears to have made a 13 cent donation.
   *
   * The new donation gets today's date as we have not passed a refund date.
   *
   * @throws \CRM_Core_Exception
   */
  public function testMakeLesserRefund(): void {
    $this->setupOriginalContribution();
    $time = time();
    // Add an earlier contribution - this will be the most recent if our contribution is
    // deleted.
    $receiveDate = date('Y-m-d', strtotime('1 year ago'));
    $this->createTestEntity('Contribution', [
      'contact_id' => $this->ids['Contact']['default'],
      'financial_type_id:name' => 'Cash',
      'total_amount' => 40,
      'source' => 'NZD' . ' ' . 200,
      'receive_date' => $receiveDate,
      'trxn_id' => "TEST_GATEWAY" . ($time - 200),
    ]);
    $this->assertContactValues($this->ids['Contact']['default'], [
      'wmf_donor.lifetime_usd_total' => 41.23,
      'wmf_donor.last_donation_date' => date('Y-m-d') . ' 04:05:06',
      'wmf_donor.last_donation_amount' => 1.23,
      'wmf_donor.last_donation_usd' => 1.23,
      'wmf_donor.' . $this->getCurrentFinancialYearTotalFieldName() => 1.23,
    ]);

    $this->processMessage([
      'gateway_parent_id' => 'E-I-E-I-O',
      'gross_currency' => 'EUR',
      'gross' => 0.98,
      'date' => date('Y-m-d H:i:s'),
      'gateway' => 'test_gateway',
      'gateway_txn_id' => 'abc',
      'type' => 'refund',
    ], 'Refund', 'refund');

    $refundContribution = Contribution::get(FALSE)
      ->addWhere('contribution_extra.parent_contribution_id', '=', $this->ids['Contribution']['original'])
      ->execute()
      ->single();

    $this->assertEquals(
      "EUR 0.25", $refundContribution['source'], 'Refund contribution has correct lesser amount'
    );
    $this->assertContactValues($this->ids['Contact']['default'], [
      'wmf_donor.lifetime_usd_total' => 40,
      'wmf_donor.last_donation_date' => date('Y-m-d 00:00:00', strtotime('1 year ago')),
      'wmf_donor.last_donation_usd' => 40,
      'wmf_donor.' . $this->getCurrentFinancialYearTotalFieldName() => 0,
      'wmf_donor.last_donation_currency' => 'NZD',
      'wmf_donor.last_donation_amount' => 200,
    ]);
  }

  /**
   * Make a refund in the wrong currency.
   */
  public function testMakeWrongCurrencyRefund(): void {
    $this->setupOriginalContribution();
    $this->expectException(WMFException::class);
    $wrong_currency = 'GBP';
    $this->processMessageWithoutQueuing([
      'gateway_parent_id' => 'E-I-E-I-O',
      'gross_currency' => $wrong_currency,
      'gross' => 1.23,
      'date' => date('Y-m-d H:i:s'),
      'gateway' => 'test_gateway',
      'type' => 'refund',
    ], 'Refund');
  }

  /**
   * Make a refund for too much.
   */
  public function testMakeScammerRefund(): void {
    $this->setupOriginalContribution();
    $this->processMessage([
      'gateway_parent_id' => 'E-I-E-I-O',
      'gross_currency' => 'EUR',
      'gross' => 101.23,
      'date' => date('Y-m-d H:i:s'),
      'gateway' => 'test_gateway',
      'type' => 'refund',
    ], 'Refund', 'refund');
    $mailing = $this->getMailing(0);
    $this->assertStringContainsString("<p>Refund amount mismatch for : {$this->ids['Contribution']['original']}, difference is 100. See http", $mailing['html']);
  }

  /**
   * Make a lesser refund in the wrong currency
   *
   * @throws \CRM_Core_Exception
   */
  public function testLesserWrongCurrencyRefund(): void {
    $this->setupOriginalContribution();
    $this->setExchangeRates(time(), ['USD' => 1, 'COP' => .01]);

    $this->createTestEntity('Contribution', [
      'contact_id' => $this->ids['Contact']['default'],
      'financial_type_id.name' => 'Cash',
      'total_amount' => 200,
      'currency' => 'USD',
      'contribution_source' => 'COP 20000',
      'contribution_extra.gateway' => 'adyen',
      'contribution_extra.gateway_txn_id' => 345,
      'trxn_id' => "TEST_GATEWAY E-I-E-I-O " . (time() + 20),
    ]);

    $this->processMessage([
      'gateway_parent_id' => 345,
      'gateway' => 'adyen',
      'gateway_txn_id' => 123,
      'gross_currency' => 'COP',
      'gross' => 5000,
      'date' => date('Y-m-d H:i:s'),
      'type' => 'refund',
    ], 'Refund', 'refund');

    $contributions = Contribution::get(FALSE)
      ->addWhere('contact_id', '=', $this->ids['Contact']['default'])
      ->execute();
    $this->assertEquals(3, count($contributions), print_r($contributions, TRUE));
    $this->assertEquals(200, $contributions[1]['total_amount']);
    $this->assertEquals('USD', $contributions[2]['currency']);
    $this->assertEquals(150, $contributions[2]['total_amount']);
    $this->assertEquals('COP 15000', $contributions[2]['source']);
  }

  /**
   * @return void
   */
  public function setupOriginalContribution(): void {
    $time = time();
    $this->setExchangeRates($time, ['USD' => 1, 'EUR' => 0.5, 'NZD' => 5]);
    $this->setExchangeRates(strtotime('1 year ago'), ['USD' => 1, 'EUR' => 0.5, 'NZD' => 5]);

    $this->createTestEntity('Contact', [
      'contact_type' => 'Individual',
      'first_name' => 'Test',
      'last_name' => 'Es',
      'debug' => 1,
    ]);
    $this->createTestEntity('Contribution', [
      'contact_id' => $this->ids['Contact']['default'],
      'financial_type_id:name' => 'Cash',
      'total_amount' => 1.23,
      'contribution_source' => 'EUR 1.23',
      'receive_date' => date('Y-m-d') . ' 04:05:06',
      'trxn_id' => 'TEST_GATEWAY E-I-E-I-O',
      'contribution_xtra.gateway' => 'test_gateway',
      'contribution_xtra.gateway_txn_id' => 'E-I-E-I-O',
    ], 'original');

    $this->assertContactValues($this->ids['Contact']['default'], [
      'wmf_donor.lifetime_usd_total' => 1.23,
      'wmf_donor.last_donation_date' => date('Y-m-d') . ' 04:05:06',
      'wmf_donor.last_donation_amount' => 1.23,
      'wmf_donor.last_donation_usd' => 1.23,
      'wmf_donor.' . $this->getCurrentFinancialYearTotalFieldName() => 1.23,
    ]);
  }

  /**
   * @param array $values
   *
   * @return array
   */
  public function getRefundMessage(array $values = []): array {
    $donation_message = $this->getDonationMessage([], TRUE, []);
    return array_merge($this->loadMessage('refund'),
      [
        'gateway' => $donation_message['gateway'],
        'gateway_parent_id' => $donation_message['gateway_txn_id'],
        'gateway_refund_id' => mt_rand(),
        'gross' => $donation_message['gross'],
        'gross_currency' => $donation_message['original_currency'],
      ], $values
    );
  }

  /**
   * @return string
   */
  public function getCurrentFinancialYearTotalFieldName(): string {
    $financialYearEnd = (date('m') > 6) ? date('Y') + 1 : date('Y');
    return 'total_' . ($financialYearEnd - 1) . '_' . $financialYearEnd;
  }

}

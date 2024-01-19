<?php

use Civi\WMFHelpers\FinanceInstrument;
use queue2civicrm\recurring\RecurringQueueConsumer;
use queue2civicrm\refund\RefundQueueConsumer;
use Civi\WMFException\WMFException;
use SmashPig\Core\DataStores\QueueWrapper;
use SmashPig\CrmLink\Messages\SourceFields;

/**
 * @group Queue2Civicrm
 */
class RefundQueueTest extends BaseWmfDrupalPhpUnitTestCase {

  /**
   * @var RefundQueueConsumer
   */
  protected $consumer;

  public function setUp(): void {
    parent::setUp();
    $this->consumer = new RefundQueueConsumer(
      'refund'
    );
  }

  public function testRefund() {
    $donation_message = new TransactionMessage();
    $refund_message = new RefundMessage(
      [
        'gateway' => $donation_message->getGateway(),
        'gateway_parent_id' => $donation_message->getGatewayTxnId(),
        'gateway_refund_id' => mt_rand(),
        'gross' => $donation_message->get('original_gross'),
        'gross_currency' => $donation_message->get('original_currency'),
      ]
    );

    exchange_rate_cache_set('USD', $donation_message->get('date'), 1);
    exchange_rate_cache_set($donation_message->get('currency'), $donation_message->get('date'), 3);

    $message_body = $donation_message->getBody();
    wmf_civicrm_contribution_message_import($message_body);
    $contributions = wmf_civicrm_get_contributions_from_gateway_id(
      $donation_message->getGateway(),
      $donation_message->getGatewayTxnId()
    );
    $this->assertEquals(1, count($contributions));
    $this->ids['Contact'][] = $contributions[0]['contact_id'];

    $this->consumer->processMessage($refund_message->getBody());
    $this->callAPISuccessGetSingle('Contribution', ['id' => $contributions[0]['id'], 'contribution_status_id' => 'Chargeback']);
  }

  public function testRefundNoPredecessor(): void {
    $this->expectException(WMFException::class);
    $this->expectExceptionCode(WMFException::MISSING_PREDECESSOR);
    $refund_message = new RefundMessage();

    $this->consumer->processMessage($refund_message->getBody());
  }

  public function testRefundEmptyRequiredField() {
    $donation_message = new TransactionMessage();
    $this->expectException(WMFException::class);
    $this->expectExceptionCode(WMFException::CIVI_REQ_FIELD);
    $refund_message = new RefundMessage(
      [
        'gateway' => $donation_message->getGateway(),
        'gateway_parent_id' => $donation_message->getGatewayTxnId(),
        'gateway_refund_id' => mt_rand(),
        'gross' => '',
        'gross_currency' => 'USD',
      ]
    );
    $this->consumer->processMessage($refund_message->getBody());
  }

  /**
   * Test refunding a mismatched amount.
   *
   * Note that we were checking against an exception - but it turned out the
   * exception could be thrown in this fn $this->queueConsumer->processMessage
   * if the exchange rate does not exist - which is not what we are testing
   * for.
   *
   * @throws \Civi\WMFException\WMFException
   * @throws \CRM_Core_Exception
   */
  public function testRefundMismatched() {
    $this->setExchangeRates(1234567, ['USD' => 1, 'PLN' => 0.5]);
    $donation_message = new TransactionMessage(
      [
        'gateway' => 'test_gateway',
        'gateway_txn_id' => mt_rand(),
      ]
    );
    $refund_message = new RefundMessage(
      [
        'gateway' => 'test_gateway',
        'gateway_parent_id' => $donation_message->getGatewayTxnId(),
        'gateway_refund_id' => mt_rand(),
        'gross' => $donation_message->get('original_gross') + 1,
        'gross_currency' => $donation_message->get('original_currency'),
      ]
    );

    $message_body = $donation_message->getBody();
    wmf_civicrm_contribution_message_import($message_body);
    $contributions = wmf_civicrm_get_contributions_from_gateway_id(
      $donation_message->getGateway(),
      $donation_message->getGatewayTxnId()
    );
    $this->ids['Contact'][] = $contributions[0]['contact_id'];
    $this->assertCount(1, $contributions);

    $this->consumer->processMessage($refund_message->getBody());
    $contributions = $this->callAPISuccess(
      'Contribution',
      'get',
      ['contact_id' => $contributions[0]['contact_id'], 'sequential' => 1]
    );
    $this->assertCount(2, $contributions['values']);
    $this->assertEquals(
      'Chargeback',
      CRM_Contribute_PseudoConstant::contributionStatus($contributions['values'][0]['contribution_status_id'])
    );
    $this->assertEquals(-.5, $contributions['values'][1]['total_amount']);
  }

  /*
   * Refunds raised by Paypal do not indicate whether the initial
   * payment was taken using the paypal express checkout (paypal_ec) integration or
   * the legacy paypal integration (paypal). We try to work this out by checking for
   * the presence of specific values in messages sent over, but it appears this
   * isn't watertight as we've seen refunds failing due to incorrect mappings
   * on some occasions.
   *
   * To mitigate this we now fall back to the alternative gateway if no match is
   * found for the gateway supplied.
   *
   */
  public function testPaypalExpressFallback() {
    // add a paypal_ec donation
    $donation_message = new TransactionMessage(
      [
        'gateway' => 'paypal_ec',
        'gateway_txn_id' => mt_rand(),
      ]
    );

    $this->setExchangeRates($donation_message->get('date'), [
      'USD' => 1,
      'PLN' => 0.5,
    ]);

    $body = $donation_message->getBody();
    wmf_civicrm_contribution_message_import($body);

    // simulate a mismapped paypal legacy refund
    $refund_message = new RefundMessage(
      [
        'gateway' => 'paypal',
        'gateway_parent_id' => $donation_message->getGatewayTxnId(),
        'gateway_refund_id' => mt_rand(),
        'gross' => $donation_message->get('original_gross') + 1,
        'gross_currency' => $donation_message->get('original_currency'),
      ]
    );

    $contributions = wmf_civicrm_get_contributions_from_gateway_id(
      $donation_message->getGateway(),
      $donation_message->getGatewayTxnId()
    );
    $this->ids['Contact'][] = $contributions[0]['contact_id'];
    $this->assertEquals(1, count($contributions));

    $this->consumer->processMessage($refund_message->getBody());

    $this->callAPISuccessGetSingle('Contribution', [
      'id' => $contributions[0]['id'],
      'contribution_status_id' => 'Chargeback',
    ]);

  }

  /**
   * @see testPaypalExpressFallback
   */
  public function testPaypalLegacyFallback() {
    // add a paypal legacy donation
    $donation_message = new TransactionMessage(
      [
        'gateway' => 'paypal',
        'gateway_txn_id' => mt_rand(),
      ]
    );

    $this->setExchangeRates($donation_message->get('date'), [
      'USD' => 1,
      'PLN' => 0.5,
    ]);

    $body = $donation_message->getBody();
    wmf_civicrm_contribution_message_import($body);

    // simulate a mismapped paypal_ec refund
    $refund_message = new RefundMessage(
      [
        'gateway' => 'paypal_ec',
        'gateway_parent_id' => $donation_message->getGatewayTxnId(),
        'gateway_refund_id' => mt_rand(),
        'gross' => $donation_message->get('original_gross') + 1,
        'gross_currency' => $donation_message->get('original_currency'),
      ]
    );

    $contributions = wmf_civicrm_get_contributions_from_gateway_id(
      $donation_message->getGateway(),
      $donation_message->getGatewayTxnId()
    );
    $this->ids['Contact'][] = $contributions[0]['contact_id'];
    $this->assertEquals(1, count($contributions));

    $this->consumer->processMessage($refund_message->getBody());

    $this->callAPISuccessGetSingle('Contribution', [
      'id' => $contributions[0]['id'],
      'contribution_status_id' => 'Chargeback',
    ]);

  }

  /**
   * Ensure that Civi core code (CRM_Contribute_BAO_ContributionRecur::updateOnTemplateUpdated)
   * does not edit contribution_recur rows to match the currency and amount of an associated
   * contribution when the contribution is edited.
   *
   * @covers \Civi\WmfHooks\ContributionRecur::pre
   * @throws \CRM_Core_Exception
   */
  public function testRefundDoesNotChangeRecurCurrency(): void {
    $initialDonation = [
      'gateway_txn_id' => 'HJZJ4JZVLGNG5S82',
      'contribution_tracking_id' => 13,
      'utm_source' => '..rcc',
      'language' => 'en',
      'email' => 'jwales@example.com',
      'first_name' => 'Jimmy',
      'last_name' => 'Wales',
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
      'financial_type_id' => \Civi\WMFHelpers\ContributionRecur::getFinancialTypeForFirstContribution(),
    ];
    $this->setExchangeRates(1669082766, [
      'USD' => 1,
      'EUR' => 1.1
    ]);

    $contribution = wmf_civicrm_contribution_message_import($initialDonation);
    $this->ids['Contribution'][] = $contribution['id'];
    $this->ids['ContributionRecur'][] = $recurId = $contribution['contribution_recur_id'];

    // Import will convert the contribution to USD but leave the contribution_recur as EUR
    $this->assertEquals('USD', $contribution['currency']);
    $originalRecurRecord = $this->callAPISuccessGetSingle('ContributionRecur', ['id' => $recurId]);
    $this->assertEquals('EUR', $originalRecurRecord['currency']);
    $refundMessage = [
      'type' => 'refund',
      'date' => 1669082866,
      'gateway' => 'adyen',
      'gateway_parent_id' => 'HJZJ4JZVLGNG5S82',
      'gateway_refund_id' => 'HJZJ4JZVLGNG5S82',
      'gross' => 10.00,
      'gross_currency' => 'EUR',
    ];
    $this->consumer->processMessage($refundMessage);
    // Make sure that our pre-update hook in wmf-civicrm's WMFHooks\ContributionRecur has
    // prevented the Civi core code from mutating the recur row's currency.
    $newRecurRecord = $this->callAPISuccessGetSingle('ContributionRecur', ['id' => $recurId]);
    $this->assertEquals('EUR', $newRecurRecord['currency']);
  }

  /**
    * Test refunding a mismatched refund currency.
    *
    *
    * @throws \Civi\WMFException\WMFException
    * @throws \CRM_Core_Exception
    */
  public function testRefundMismatchedRefundCurrency() {
    $this->setExchangeRates(1234567, ['USD' => 1, 'PLN' => 0.5]);
    $donation_message = new TransactionMessage(
      [
        'gateway' => 'test_gateway',
        'gateway_txn_id' => mt_rand(),
      ]
    );
    $refund_message = new RefundMessage(
      [
        'gateway' => 'test_gateway',
        'gateway_parent_id' => $donation_message->getGatewayTxnId(),
        'gateway_refund_id' => mt_rand(),
        'gross' => $donation_message->get('original_gross')*0.5,
        'gross_currency' => 'USD',
      ]
    );

    $message_body = $donation_message->getBody();
    wmf_civicrm_contribution_message_import($message_body);
    $contributions = wmf_civicrm_get_contributions_from_gateway_id(
      $donation_message->getGateway(),
      $donation_message->getGatewayTxnId()
    );
    $this->ids['Contact'][] = $contributions[0]['contact_id'];
    $this->assertCount(1, $contributions);

    $this->consumer->processMessage($refund_message->getBody());
    $contributions = $this->callAPISuccess(
      'Contribution',
      'get',
      ['contact_id' => $contributions[0]['contact_id'], 'sequential' => 1]
    );
    $this->assertCount(1, $contributions['values']);
    $this->assertEquals(
      'Chargeback',
      CRM_Contribute_PseudoConstant::contributionStatus($contributions['values'][0]['contribution_status_id'])
    );
  }

  public function testChargebackRecurring(): void {
    $signupMessage = new RecurringSignupMessage(['subscr_id' => mt_rand()]);
    $subscrTime = $signupMessage->get('date');
    exchange_rate_cache_set('USD', $subscrTime, 1);
    exchange_rate_cache_set($signupMessage->get('currency'), $subscrTime, 2);
    (new RecurringQueueConsumer('recurring'))->processMessage($signupMessage->getBody());
    $recurRecord = $this->callAPISuccessGetSingle('ContributionRecur', ['trxn_id' => $signupMessage->get('subscr_id')]);
    $this->ids['ContributionRecur'][] = $recurRecord['id'];
    $donationMessage = new TransactionMessage([
      'gateway' => 'test_gateway',
      'gateway_txn_id' => mt_rand(),
      'contribution_recur_id' => $recurRecord['id'],
    ]);
    $messageBody = $donationMessage->getBody();
    $contribution = wmf_civicrm_contribution_message_import($messageBody);
    $this->ids['Contribution'][] = $contribution['id'];
    $refundMessage = new RefundMessage([
      'gateway' => 'test_gateway',
      'gateway_parent_id' => $donationMessage->getGatewayTxnId(),
      'gateway_refund_id' => mt_rand(),
      'type' => 'chargeback',
      'gross' => $donationMessage->get('original_gross'),
      'gross_currency' => $donationMessage->get('original_currency'),
    ]);
    $this->consumer->processMessage($refundMessage->getBody());
    $cancelMessage = QueueWrapper::getQueue('recurring')->pop();
    SourceFields::removeFromMessage($cancelMessage);
    $this->assertArrayHasKey('payment_instrument_id', $cancelMessage);
    unset($cancelMessage['payment_instrument_id']);
    $this->assertEquals(
      [
        'contribution_recur_id' => $recurRecord['id'],
        'txn_type' => 'subscr_cancel',
        'cancel_reason' => 'Automatically cancelling because we received a chargeback',
      ],
      $cancelMessage
    );
  }
}

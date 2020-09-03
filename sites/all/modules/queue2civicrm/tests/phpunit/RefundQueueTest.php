<?php

use queue2civicrm\refund\RefundQueueConsumer;

/**
 * @group Queue2Civicrm
 */
class RefundQueueTest extends BaseWmfDrupalPhpUnitTestCase {

  /**
   * @var RefundQueueConsumer
   */
  protected $consumer;

  public function setUp() {
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

    $this->consumer->processMessage($refund_message->getBody());
    $this->callAPISuccessGetSingle('Contribution', ['id' => $contributions[0]['id'], 'contribution_status_id' => 'Chargeback']);
  }

  /**
   * @expectedException WmfException
   * @expectedExceptionCode WmfException::MISSING_PREDECESSOR
   */
  public function testRefundNoPredecessor() {
    $refund_message = new RefundMessage();

    $this->consumer->processMessage($refund_message->getBody());
  }

  /**
   * Test refunding a mismatched amount.
   *
   * Note that we were checking against an exception - but it turned out the
   * exception could be thrown in this fn $this->queueConsumer->processMessage
   * if the exchange rate does not exist - which is not what we are testing
   * for.
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
    $this->assertEquals(1, count($contributions));

    $this->consumer->processMessage($refund_message->getBody());
    $contributions = $this->callAPISuccess(
      'Contribution',
      'get',
      ['contact_id' => $contributions[0]['contact_id'], 'sequential' => 1]
    );
    $this->assertEquals(2, count($contributions['values']));
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
    $this->assertEquals(1, count($contributions));

    $this->consumer->processMessage($refund_message->getBody());

    $this->callAPISuccessGetSingle('Contribution', [
      'id' => $contributions[0]['id'],
      'contribution_status_id' => 'Chargeback',
    ]);

  }

}

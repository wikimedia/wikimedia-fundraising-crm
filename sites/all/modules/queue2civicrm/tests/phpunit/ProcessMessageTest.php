<?php

/**
 * @group Pipeline
 * @group Queue2Civicrm
 */
class ProcessMessageTest extends BaseWmfDrupalPhpUnitTestCase {
    public static function getInfo() {
        return array(
            'name' => 'Process Message',
            'group' => 'Pipeline',
            'description' => 'Push messages through the queue intake functions.',
        );
    }

    /**
     * Process an ordinary (one-time) donation message
     */
    public function testDonation() {
        $message = new TransactionMessage();
        $message2 = new TransactionMessage();

        exchange_rate_cache_set( 'USD', $message->get( 'date' ), 1 );
        exchange_rate_cache_set( $message->get( 'currency' ), $message->get( 'date' ), 3 );

        queue2civicrm_import( $message );
        queue2civicrm_import( $message2 );

        $contributions = wmf_civicrm_get_contributions_from_gateway_id( $message->getGateway(), $message->getGatewayTxnId() );
        $this->assertEquals( 1, count( $contributions ) );

        $contributions2 = wmf_civicrm_get_contributions_from_gateway_id( $message2->getGateway(), $message2->getGatewayTxnId() );
        $this->assertEquals( 1, count( $contributions2 ) );

        $this->assertNotEquals( $contributions[0]['contact_id'], $contributions2[0]['contact_id'] );
    }

    public function testRecurring() {
        $subscr_id = mt_rand();
        $values = array( 'subscr_id' => $subscr_id );
        $signup_message = new RecurringSignupMessage( $values );
        $message = new RecurringPaymentMessage( $values );
        $message2 = new RecurringPaymentMessage( $values );

        $subscr_time = strtotime( $signup_message->get( 'subscr_date' ) );
        exchange_rate_cache_set( 'USD', $subscr_time, 1 );
        exchange_rate_cache_set( $signup_message->get('mc_currency'), $subscr_time, 3 );
        $payment_time = strtotime( $message->get( 'payment_date' ) );
        exchange_rate_cache_set( 'USD', $payment_time, 1 );
        exchange_rate_cache_set( $message->get('mc_currency'), $payment_time, 3 );

        recurring_import( $signup_message );
        recurring_import( $message );
        recurring_import( $message2 );

        $recur_record = wmf_civicrm_get_recur_record( $subscr_id );
        $this->assertNotEquals( false, $recur_record );

        $contributions = wmf_civicrm_get_contributions_from_gateway_id( $message->getGateway(), $message->getGatewayTxnId() );
        $this->assertEquals( 1, count( $contributions ) );
        $this->assertEquals( $recur_record->id, $contributions[0]['contribution_recur_id']);

        $contributions2 = wmf_civicrm_get_contributions_from_gateway_id( $message2->getGateway(), $message2->getGatewayTxnId() );
        $this->assertEquals( 1, count( $contributions2 ) );
        $this->assertEquals( $recur_record->id, $contributions2[0]['contribution_recur_id']);

        $this->assertEquals( $contributions[0]['contact_id'], $contributions2[0]['contact_id'] );
    }

    /**
     * @expectedException WmfException
     * @expectedExceptionCode WmfException::MISSING_PREDECESSOR
     */
    public function testRecurringNoPredecessor() {
        $message = new RecurringPaymentMessage( array(
            'subscr_id' => mt_rand(),
        ) );

        $payment_time = strtotime( $message->get( 'payment_date' ) );
        exchange_rate_cache_set( 'USD', $payment_time, 1 );
        exchange_rate_cache_set( $message->get('mc_currency'), $payment_time, 3 );

        recurring_import( $message );
    }

    /**
     * @expectedException WmfException
     * @expectedExceptionCode WmfException::INVALID_RECURRING
     */
    public function testRecurringNoSubscrId() {
        $message = new RecurringPaymentMessage( array(
            'subscr_id' => null,
        ) );

        $payment_time = strtotime( $message->get( 'payment_date' ) );
        exchange_rate_cache_set( 'USD', $payment_time, 1 );
        exchange_rate_cache_set( $message->get('mc_currency'), $payment_time, 3 );

        recurring_import( $message );
    }

    public function testRefund() {
        $donation_message = new TransactionMessage();
        $refund_message = new RefundMessage( array(
            'gateway' => $donation_message->getGateway(),
            'gateway_parent_id' => $donation_message->getGatewayTxnId(),
            'gateway_refund_id' => mt_rand(),
            'gross' => $donation_message->get( 'original_gross' ),
            'gross_currency' => $donation_message->get( 'original_currency' ),
        ) );

        exchange_rate_cache_set( 'USD', $donation_message->get('date'), 1 );
        exchange_rate_cache_set( $donation_message->get('currency'), $donation_message->get('date'), 3 );

        queue2civicrm_import( $donation_message );
        $contributions = wmf_civicrm_get_contributions_from_gateway_id( $donation_message->getGateway(), $donation_message->getGatewayTxnId() );
        $this->assertEquals( 1, count( $contributions ) );

        refund_import( $refund_message );
        $contributions = wmf_civicrm_get_contributions_from_gateway_id( $refund_message->getGateway(), $refund_message->getGatewayTxnId() );
        $this->assertEquals( 1, count( $contributions ) );
    }

    /**
     * @expectedException WmfException
     * @expectedExceptionCode WmfException::MISSING_PREDECESSOR
     */
    public function testRefundNoPredecessor() {
        $refund_message = new RefundMessage();

        refund_import( $refund_message );
    }

    /**
     * @expectedException WmfException
     * @expectedExceptionCode WmfException::INVALID_MESSAGE
     */
    public function testRefundMismatched() {
        $donation_message = new TransactionMessage( array(
            'gateway' => 'test_gateway',
            'gateway_txn_id' => mt_rand(),
        ) );
        $refund_message = new RefundMessage( array(
            'gateway' => 'test_gateway',
            'gateway_parent_id' => $donation_message->getGatewayTxnId(),
            'gateway_refund_id' => mt_rand(),
            'gross' => $donation_message->get( 'original_gross' ) + 1,
            'gross_currency' => $donation_message->get( 'original_currency' ),
        ) );

        queue2civicrm_import( $donation_message );
        $contributions = wmf_civicrm_get_contributions_from_gateway_id( $donation_message->getGateway(), $donation_message->getGatewayTxnId() );
        $this->assertEquals( 1, count( $contributions ) );

        refund_import( $refund_message );
    }
}

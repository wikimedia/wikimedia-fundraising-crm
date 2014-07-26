<?php

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

        queue2civicrm_import( $message );

        $contributions = wmf_civicrm_get_contributions_from_gateway_id( $message->getGateway(), $message->getGatewayTxnId() );
        $this->assertEquals( 1, count( $contributions ) );
    }

    public function testRecurring() {
        $shared_id = array( 'subscr_id' => mt_rand() );
        $signup_message = new RecurringSignupMessage( $shared_id );
        $message = new RecurringPaymentMessage( $shared_id );

        recurring_import( $signup_message );
        recurring_import( $message );

        $contributions = wmf_civicrm_get_contributions_from_gateway_id( $message->getGateway(), $message->getGatewayTxnId() );
        $this->assertEquals( 1, count( $contributions ) );
    }

    /**
     * @expectedException WmfException
     * @expectedExceptionCode MISSING_PREDECESSOR
     */
    public function testRecurringNoPredecessor() {
        $message = new RecurringPaymentMessage( array(
            'subscr_id' => mt_rand(),
        ) );

        recurring_import( $message );
    }

    /**
     * @expectedException WmfException
     * @expectedExceptionCode INVALID_RECURRING
     */
    public function testRecurringNoSubscrId() {
        $message = new RecurringPaymentMessage( array(
            'subscr_id' => null,
        ) );

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

        queue2civicrm_import( $donation_message );
        $contributions = wmf_civicrm_get_contributions_from_gateway_id( $donation_message->getGateway(), $donation_message->getGatewayTxnId() );
        $this->assertEquals( 1, count( $contributions ) );

        refund_import( $refund_message );
        $contributions = wmf_civicrm_get_contributions_from_gateway_id( $refund_message->getGateway(), $refund_message->getGatewayTxnId() );
        $this->assertEquals( 1, count( $contributions ) );
    }

    /**
     * @expectedException WmfException
     * @expectedExceptionCode MISSING_PREDECESSOR
     */
    public function testRefundNoPredecessor() {
        $refund_message = new RefundMessage();

        refund_import( $refund_message );
    }

    /**
     * @expectedException WmfException
     * @expectedExceptionCode INVALID_MESSAGE
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

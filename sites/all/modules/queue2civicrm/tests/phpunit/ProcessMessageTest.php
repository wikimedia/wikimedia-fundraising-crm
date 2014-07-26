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

        queue2civicrm_import( $message->getBody() );

        $contributions = wmf_civicrm_get_contributions_from_gateway_id( $message->getGateway(), $message->getGatewayTxnId() );
        $this->assertEquals( 1, count( $contributions ) );
    }

    public function testRecurring() {
        $shared_id = array( 'subscr_id' => mt_rand() );
        $signup_message = new RecurringSignupMessage( $shared_id );
        $message = new RecurringPaymentMessage( $shared_id );

        recurring_import( $signup_message->getBody() );
        recurring_import( $message->getBody() );
    }

    /**
     * @expectedException WmfException
     * @expectedExceptionCode MISSING_PREDECESSOR
     */
    public function testRecurringNoPredecessor() {
        $message = new RecurringPaymentMessage( array(
            'subscr_id' => mt_rand(),
        ) );

        recurring_import( $message->getBody() );
    }

    /**
     * @expectedException WmfException
     * @expectedExceptionCode INVALID_RECURRING
     */
    public function testRecurringNoSubscrId() {
        $message = new RecurringPaymentMessage( array(
            'subscr_id' => null,
        ) );

        recurring_import( $message->getBody() );
    }
}

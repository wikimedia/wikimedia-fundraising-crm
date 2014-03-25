<?php

class ProcessMessageTest extends BaseWmfDrupalPhpUnitTestCase {
    public static function getInfo() {
        return array(
            'name' => 'Process Message',
            'group' => 'Pipeline',
            'description' => 'Push messages through the queue intake functions.',
        );
    }

    /*
    public function setUp() {
    }

    public function tearDown() {
    }
    */

    /**
     * Process an ordinary (one-time) donation message
     */
    public function testDonation() {
        $message = new TransactionMessage();

        queue2civicrm_import( $message->getBody() );

        // TODO: check importedness
    }

    public function testRecurring() {
    }

    public function testRefund() {
    }

    public function testUnsubscribe() {
    }
}

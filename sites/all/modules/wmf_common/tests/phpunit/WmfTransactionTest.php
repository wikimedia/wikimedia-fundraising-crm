<?php

class WmfTransactionTestCase extends BaseWmfDrupalPhpUnitTestCase {
    public function testParseUniqueId() {
        $transaction = WmfTransaction::from_unique_id( "RFD RECURRING GLOBALCOLLECT 1234 432" );
        $this->assertEquals(
            $transaction->gateway_txn_id, "1234",
            "5-argument form gateway_txn_id is parsed correctly." );
        $this->assertEquals(
            true, $transaction->is_refund,
            "refund flag parsed" );
        $this->assertEquals(
            true, $transaction->is_recurring,
            "recurring flag parsed" );
        $this->assertEquals(
            "globalcollect", strtolower( $transaction->gateway ),
            "gateway is correctly parsed" );
        $this->assertEquals(
            "432", $transaction->timestamp,
            "timestamp is correctly parsed" );
        $this->assertEquals(
            $transaction->get_unique_id(), "RFD RECURRING GLOBALCOLLECT 1234",
            "5-argument form is renormalized to 4-form" );

        $transaction = WmfTransaction::from_unique_id( "RFD GLOBALCOLLECT 1234 432" );
        $this->assertEquals(
            $transaction->gateway_txn_id, "1234",
            "4-argument form gateway_txn_id is parsed correctly." );
        $this->assertEquals(
            true, $transaction->is_refund,
            "refund flag parsed" );
        $this->assertEquals(
            "432", $transaction->timestamp,
            "timestamp is correctly parsed" );
        $this->assertEquals(
            $transaction->get_unique_id(), "RFD GLOBALCOLLECT 1234",
            "4-argument form is renormalized correctly" );

        $transaction = WmfTransaction::from_unique_id( "GLOBALCOLLECT 1234x 432" );
        $this->assertEquals(
            $transaction->gateway_txn_id, "1234x",
            "3-argument form gateway_txn_id is parsed correctly." );
        $this->assertEquals(
            $transaction->get_unique_id(), strtoupper( "GLOBALCOLLECT 1234x" ),
            "3-argument form is renormalized correctly" );

        $transaction = WmfTransaction::from_unique_id( "GLOBALCOLLECT 1234" );
        $this->assertEquals(
            $transaction->gateway_txn_id, "1234",
            "2-argument form gateway_txn_id is parsed correctly." );
        $this->assertNull( $transaction->timestamp,
            "timestamp is not unnecessarily invented" );
    }

    public function testParseMessage() {
        $msg = array(
            'gateway' => "globalcollect",
            'gateway_txn_id' => "1234",
            'recurring' => null,
        );
        $transaction = WmfTransaction::from_message( $msg );
        $this->assertEquals(
            "1234", $transaction->gateway_txn_id,
            "parsed message gateway_txn_id is correct" );
        $this->assertEquals( 1,
            preg_match( "/GLOBALCOLLECT 1234/", $transaction->get_unique_id() ),
            "parsed message has correct trxn_id" );
    }
}

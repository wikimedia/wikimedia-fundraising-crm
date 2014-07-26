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
            $transaction->get_unique_id(), "RFD RECURRING GLOBALCOLLECT 1234 432",
            "5-argument form is not mangled" );

        $transaction = WmfTransaction::from_unique_id( "RFD GLOBALCOLLECT 1234 432" );
        $this->assertEquals(
            $transaction->gateway_txn_id, "1234",
            "4-argument form gateway_txn_id is parsed correctly." );
        $this->assertEquals(
            true, $transaction->is_refund,
            "refund flag parsed" );
        $this->assertEquals(
            $transaction->get_unique_id(), "RFD GLOBALCOLLECT 1234 432",
            "4-argument form is not mangled" );

        $transaction = WmfTransaction::from_unique_id( "GLOBALCOLLECT 1234x 432" );
        $this->assertEquals(
            $transaction->gateway_txn_id, "1234x",
            "3-argument form gateway_txn_id is parsed correctly." );
        $this->assertEquals(
            $transaction->get_unique_id(), strtoupper( "GLOBALCOLLECT 1234x 432" ),
            "3-argument form is not mangled" );

        $transaction = WmfTransaction::from_unique_id( "GLOBALCOLLECT 1234" );
        $this->assertEquals(
            $transaction->gateway_txn_id, "1234",
            "2-argument form gateway_txn_id is parsed correctly." );
        $this->assertEquals( 1,
            preg_match( "/GLOBALCOLLECT 1234 [0-9]+/", $transaction->get_unique_id() ),
            "2-argument form is given a timestamp" );
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
            preg_match( "/GLOBALCOLLECT 1234 [0-9]+/", $transaction->get_unique_id() ),
            "parsed message is given a timestamp" );
    }

    /**
     * @expectedException WmfException
     * @expectedExceptionCode INVALID_MESSAGE
     */
    function testInvalidEmptyId() {
        $transaction = WmfTransaction::from_unique_id( "" );
    }

    /**
     * @expectedException WmfException
     * @expectedExceptionCode INVALID_MESSAGE
     */
    function testInvalidAlmostEmptyId() {
        $transaction = WmfTransaction::from_unique_id( 'RFD RECURRING' );
    }

    /**
     * @expectedException WmfException
     * @expectedExceptionCode INVALID_MESSAGE
     */
    function testInvalidWhitespaceId() {
        $transaction = WmfTransaction::from_unique_id( 'RFD RECURRING ' );
    }

    /**
     * @expectedException WmfException
     * @expectedExceptionCode INVALID_MESSAGE
     */
    function testInvalidExtraPartsId() {
        $transaction = WmfTransaction::from_unique_id( 'TEST_GATEWAY 123 1234 EXTRA_PART' );
    }

    /**
     * @expectedException WmfException
     * @expectedExceptionCode INVALID_MESSAGE
     */
    function testInvalidTimestampId() {
        $transaction = WmfTransaction::from_unique_id( 'TEST_GATEWAY 123 BAD_TIMESTAMP' );
    }

    function testExistsNone() {
        $transaction = WmfTransaction::from_unique_id( 'TEST_GATEWAY ' . mt_rand() );
        $this->assertEquals( false, $transaction->exists() );
    }

    function testExistsOne() {
        $gateway_txn_id = mt_rand();
        $msg = array(
            'gross' => 1,
            'currency' => 'USD',
            'gateway' => 'TEST_GATEWAY',
            'gateway_txn_id' => $gateway_txn_id,
            'payment_method' => 'cc',
            'email' => 'nobody@wikimedia.org',
        );
        wmf_civicrm_contribution_message_import( $msg );
        $transaction = WmfTransaction::from_unique_id( 'TEST_GATEWAY ' . $gateway_txn_id );
        $this->assertEquals( true, $transaction->exists() );
    }

    /**
     * @expectedException NonUniqueTransaction
     */
    function testGetContributionMany() {
        $gateway_txn_id = mt_rand();
        $api = civicrm_api_classapi();
        $api->Contact->create( array(
            'contact_type' => 'Individual',
            'display_name' => 'test',
            'version' => 3,
        ) );
        $params = array(
            'contact_id' => $api->values[0]->id,
            'contribution_type' => 'Cash',
            'total_amount' => 1,
            'version' => 3,
        );
        $api->Contribution->create( $params );
        wmf_civicrm_set_custom_field_values( $api->values[0]->id, array(
            'gateway' => 'TEST_GATEWAY',
            'gateway_txn_id' => $gateway_txn_id,
        ) );
        $api->Contribution->create( $params );
        wmf_civicrm_set_custom_field_values( $api->values[0]->id, array(
            'gateway' => 'TEST_GATEWAY',
            'gateway_txn_id' => $gateway_txn_id,
        ) );

        $transaction = WmfTransaction::from_unique_id( 'TEST_GATEWAY ' . $gateway_txn_id );
        $transaction->getContribution();
    }
}

<?php

use wmf_communication\TestMailer;

class LargeDonationTest extends BaseWmfDrupalPhpUnitTestCase {
    function setUp() {
        parent::setUp();
        civicrm_initialize();

        TestMailer::setup();

        $this->original_large_donation_amount = variable_get( 'large_donation_amount', null );
        $this->original_large_donation_notifymail = variable_get( 'large_donation_notifymail', null );
        $this->threshold = 100;
        variable_set( 'large_donation_amount', $this->threshold );
        variable_set( 'large_donation_notifymail', 'notifee@localhost.net' );

        $result = civicrm_api3( 'Contact', 'create', array(
            'contact_type' => 'Individual',
            'first_name' => 'Testes',
        ) );
        $this->contact_id = $result['id'];
    }

    function tearDown() {
        variable_set( 'large_donation_amount', $this->original_large_donation_amount );
        variable_set( 'large_donation_notifymail', $this->original_large_donation_notifymail );
        parent::tearDown();
    }

    function testUnderThreshold() {
        $result = civicrm_api3( 'Contribution', 'create', array(
            'contact_id' => $this->contact_id,
            'contribution_type' => 'Cash',
            'currency' => 'USD',
            'payment_instrument' => 'Credit Card',
            'total_amount' => $this->threshold - 0.01,
            'trxn_id' => 'TEST_GATEWAY ' . mt_rand(),
        ) );

        $this->assertEquals( 0, TestMailer::countMailings() );
    }

    function testAboveThreshold() {
        $amount = $this->threshold + 0.01;
        $result = civicrm_api3( 'Contribution', 'create', array(
            'contact_id' => $this->contact_id,
            'contribution_type' => 'Cash',
            'currency' => 'USD',
            'payment_instrument' => 'Credit Card',
            'total_amount' => $amount,
            'trxn_id' => 'TEST_GATEWAY ' . mt_rand(),
            'source' => 'EUR 2020',
        ) );

        $this->assertEquals( 1, TestMailer::countMailings() );

        $mailing = TestMailer::getMailing( 0 );
        $this->assertEquals( 1, preg_match( "/{$amount}/", $mailing['html'] ),
            'Found amount in the notification email body.' );
    }
}

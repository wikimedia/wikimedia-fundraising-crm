<?php

use wmf_communication\TestMailer;

/**
 * @group LargeDonation
 */
class LargeDonationTest extends BaseWmfDrupalPhpUnitTestCase {
    function setUp() {
        parent::setUp();
        civicrm_initialize();

        TestMailer::setup();

        $this->threshold = 100;

        db_delete( 'large_donation_notification' )
            ->execute();

        db_insert( 'large_donation_notification' )
            ->fields( array(
                'addressee' => 'notifee@localhost.net',
                'threshold' => $this->threshold,
            ) )
            ->execute();

        $result = civicrm_api3( 'Contact', 'create', array(
            'contact_type' => 'Individual',
            'first_name' => 'Testes',
        ) );
        $this->contact_id = $result['id'];
    }

    function tearDown() {
        db_delete( 'large_donation_notification' )
            ->execute();

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

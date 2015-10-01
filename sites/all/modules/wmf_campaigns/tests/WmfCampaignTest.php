<?php

use wmf_communication\TestMailer;

/**
 * @group WmfCampaigns
 */
class WmfCampaignTest extends BaseWmfDrupalPhpUnitTestCase {
    public $campaign_custom_field_name;
    public $campaign_key;
    public $notification_email;
    public $contact_id;

    function setUp() {
        parent::setUp();
        civicrm_initialize();

        TestMailer::setup();

        $this->campaign_custom_field_name = wmf_civicrm_get_custom_field_name( 'Appeal' );

        $this->campaign_key = 'fooCamp' . mt_rand();
        $this->notification_email = 'notifee@localhost.net';

        civicrm_api3( 'OptionValue', 'create', array(
            'option_group_id' => WMF_CAMPAIGNS_OPTION_GROUP_NAME,
            'name' => $this->campaign_key,
        ) );

        $result = civicrm_api3( 'Contact', 'create', array(
            'contact_type' => 'Individual',
            'first_name' => 'Testes',
        ) );
        $this->contact_id = $result['id'];

        db_merge( 'wmf_campaigns_campaign' )
            ->key( array( 'campaign_key' => $this->campaign_key ) )
            ->fields( array(
                'campaign_key' => $this->campaign_key,
                'notification_email' => $this->notification_email,
            ) )
            ->execute();
    }

    function testMatchingDonation() {
        $result = civicrm_api3( 'Contribution', 'create', array(
            'contact_id' => $this->contact_id,
            'contribution_type' => 'Cash',
            'currency' => 'USD',
            'payment_instrument' => 'Credit Card',
            'total_amount' => '1.23',
            'trxn_id' => 'TEST_GATEWAY ' . mt_rand(),
            $this->campaign_custom_field_name => $this->campaign_key,
        ) );

        $this->assertEquals( 1, TestMailer::countMailings() );

        $mailing = TestMailer::getMailing( 0 );
        $this->assertNotEquals( false, strpos( $mailing['html'], $this->campaign_key ),
            'Campaign name found in notification email.' );
    }

    function testNonMatchingDonation() {
        $result = civicrm_api3( 'Contribution', 'create', array(
            'contact_id' => $this->contact_id,
            'contribution_type' => 'Cash',
            'currency' => 'USD',
            'payment_instrument' => 'Credit Card',
            'total_amount' => '1.23',
            'trxn_id' => 'TEST_GATEWAY ' . mt_rand(),
            $this->campaign_custom_field_name => $this->campaign_key . "NOT",
        ) );

        $this->assertEquals( 0, TestMailer::countMailings() );
    }

    function testNoCampaignDonation() {
        $result = civicrm_api3( 'Contribution', 'create', array(
            'contact_id' => $this->contact_id,
            'contribution_type' => 'Cash',
            'currency' => 'USD',
            'payment_instrument' => 'Credit Card',
            'total_amount' => '1.23',
            'trxn_id' => 'TEST_GATEWAY ' . mt_rand(),
        ) );

        $this->assertEquals( 0, TestMailer::countMailings() );
    }
}

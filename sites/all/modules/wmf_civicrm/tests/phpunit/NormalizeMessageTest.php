<?php

/**
 * @group Pipeline
 * @group WmfCivicrm
 */
class NormalizeMessageTest extends BaseWmfDrupalPhpUnitTestCase {
    public static function getInfo() {
        return array(
            'name' => 'WmfCivicrm message normalization',
            'group' => 'Pipeline',
            'description' => 'Checks for queue message normalization behavior',
        );
    }

    public function testDoubleNormalization() {
        // Start with a message already in normal form, to make comparison easy
        $original_msg = array(
            'anonymous' => 0,
            'check_number' => '',
            'city' => '',
            'comment' => '',
            'contact_id' => mt_rand(),
            'contact_groups' => array(),
            'contact_tags' => array(),
            'contribution_recur_id' => mt_rand(),
            'contribution_tags' => array(),
            'contribution_tracking_id' => mt_rand(),
            'contribution_tracking_update' => '1',
            'contribution_type' => 'cash', // FIXME
            'contribution_type_id' => '9', // FIXME
            'country' => 'IL',
            'create_date' => time() + 11,
            'currency' => 'USD',
            'date' => time() + 1,
            'effort_id' => '2',
            'email' => 'test.es@localhost.net',
            'fee' => 0.5,
            'first_name' => 'test',
            'gateway' => 'paypal',
            'gateway_txn_id' => '1234AB1234-2',
            'gross' => 5.8,
            'last_name' => 'es',
            'letter_code' => '',
            'middle_name' => '',
            'net' => 5.29,
            'optout' => 0,
            'order_id' => mt_rand(),
            'organization_name' => '',
            'original_currency' => 'ILS',
            'original_gross' => '20.00',
            'payment_date' => time(),
            'payment_instrument_id' => '25',
            'payment_instrument' => 'Paypal',
            'postal_code' => '',
            'postmark_date' => null,
            'recurring' => '1',
            'soft_credit_to' => null,
            'soft_credit_to_id' => null,
            'source_enqueued_time' => time() + 2,
            'source_host' => 'thulium',
            'source_name' => 'PayPal IPN (legacy)',
            'source_run_id' => mt_rand(),
            'source_type' => 'listener',
            'source_version' => 'legacy',
            'start_date' => time() + 10,
            'state_province' => '',
            'street_address' => '',
            'subscr_id' => 'TEST-S-1234567' . mt_rand(),
            'supplemental_address_1' => '',
            'supplemental_address_2' => '',
            'thankyou_date' => '',
            'txn_type' => 'subscr_payment',
            'utm_campaign' => '',
        );

        $msg = $original_msg;
        $normal_msg_1 = wmf_civicrm_normalize_msg( $msg );
        $this->assertEquals( $original_msg, $normal_msg_1 );
        $normal_msg_2 = wmf_civicrm_normalize_msg( $normal_msg_1 );
        $this->assertEquals( $original_msg, $normal_msg_2 );
    }

	public function testEmptyNet() {
		$msg = array(
			'gateway' => 'adyen',
			'payment_method' => 'cc',
			'first_name' => 'blah',
			'last_name' => 'wah',
			'country' => 'US',
			'currency' => 'USD',
			'gross' => '1.00',
			'net' => '',
			'fee' => '0.21',
		);
		$normalized = wmf_civicrm_normalize_msg( $msg );
		$this->assertEquals( 0.79, $normalized['net'] );
	}
}

<?php

class ImportMessageTest extends BaseWmfDrupalPhpUnitTestCase {
    public static function getInfo() {
        return array(
            'name' => 'Import Message',
            'group' => 'Pipeline',
            'description' => 'Attempt contribution message import.',
        );
    }

    public function setUp() {
        parent::setUp();

        $api = civicrm_api_classapi();

        // TODO: clean up the fixtures
        $contact_params = array(
            'contact_type' => 'Individual',
            'first_name' => 'Test',
            'last_name' => 'Es',

            'version' => 3,
        );
        $api->Contact->Create( $contact_params );
        $this->contact_id = $api->id;

        $this->recur_amount = '1.23';

        $contribution_params = array(
            'contact_id' => $this->contact_id,
            'amount' => $this->recur_amount,
            'currency' => 'USD',
            'frequency_unit' => 'month',
            'frequency_interval' => '1',
            'installments' => '0',
            'start_date' => wmf_common_date_unix_to_civicrm( time() ),
            'create_date' => wmf_common_date_unix_to_civicrm( time() ),
            'cancel_date' => null,
            'processor_id' => 1,
            'cycle_day' => '1',
            'next_sched_contribution' => null,
            'trxn_id' => 'RECURRING TEST_GATEWAY 123-1 ' . time(),

            'version' => 3,
        );
        $api->ContributionRecur->Create( $contribution_params );
        $this->contribution_recur_id = $api->id;
    }

    /**
     * @XXX doesn't stupid work cos of member vars: dataProvider messageProvider
     */
    public function testMessageInsert() {
        foreach ( $this->messageProvider() as $test ) {
            list( $msg, $expected_contribution ) = $test;

            // FIXME
            $this->run_random_id = mt_rand();
            $msg['gateway_txn_id'] = $this->run_random_id;

            $contribution = wmf_civicrm_contribution_message_import( $msg );

            // Synthesize trxn_id so it matches the random id we just used
            $expected_transaction = new WmfTransaction();
            $expected_transaction->gateway = $msg['gateway'];
            $expected_transaction->gateway_txn_id = $msg['gateway_txn_id'];
            $expected_transaction->recurring = $msg['recurring'];
            $expected_transaction->recur_sequence = ( isset( $msg['effort_id'] ) ? $msg['effort_id'] : null );
            $expected_contribution['trxn_id'] = $expected_transaction->get_unique_id();
            $this->stripTrxnIdTimestamp( $expected_contribution );

            $this->stripUniques( $contribution );

            // Strip contact_id if we are have no expectation
            if ( empty( $expected_contribution['contact_id'] ) ) {
                unset( $contribution['contact_id'] );
            }

            $this->assertEquals( $expected_contribution, $contribution );
        }
    }

    /**
     * Make sure we import 'Do Not Solicit' values to the wmf_donor table
     */
    public function testImportDoNotSolicit() {
        $msg = array(
            'email' => 'nobody@wikimedia.org',
            'gross' => '1.23',
            'currency' => 'USD',
            'payment_method' => 'cc',
            'gateway' => 'test_gateway',
            'do_not_solicit' => 'Y',
            'gateway_txn_id' => mt_rand(),
        );
        $contribution = wmf_civicrm_contribution_message_import( $msg );
        $donor_fields = wmf_civicrm_contribution_get_custom_values(
            $contribution['contact_id'],
            array( 'do_not_solicit' ),
            'wmf_donor'
        );
        $this->assertEquals( '1', $donor_fields['do_not_solicit'] );
    }

    /**
     * Check that wmf_donor fields are updated correctly
     */
    public function testImportWmfDonor() {
        $msg = array(
            'email' => 'nobody5@wikimedia.org',
            'gross' => '7.55',
            'currency' => 'USD',
            'payment_method' => 'cc',
            'gateway' => 'test_gateway',
			'date' => '2014-07-02',
            'gateway_txn_id' => mt_rand(),
        );
        $contribution = wmf_civicrm_contribution_message_import( $msg );
        $donor_fields = wmf_civicrm_contribution_get_custom_values(
            $contribution['contact_id'],
            array(
				'is_2014_donor',
				'is_2013_donor',
				'last_donation_date',
				'last_donation_usd',
				'lifetime_usd_total',
			),
            'wmf_donor'
        );
        $this->assertEquals( '1', $donor_fields['is_2014_donor'] );
		$this->assertEquals( '0', $donor_fields['is_2013_donor'] );
		$this->assertEquals( '2014-07-02', substr( $donor_fields['last_donation_date'], 0, 10 ) );
		$this->assertEquals( '7.55', $donor_fields['last_donation_usd'] );
		$this->assertEquals( '7.55', $donor_fields['lifetime_usd_total'] );
    }

    /**
     * Remove unique stuff which cannot be expected
     */
    function stripUniques( &$contribution ) {
        $isNumber = array(
            'id',
            'receive_date',
        );
        foreach ( $isNumber as $field ) {
            $this->assertGreaterThan( 0, $contribution[$field] );
            unset( $contribution[$field] );
        }

        $this->stripTrxnIdTimestamp( $contribution );
    }

    function stripTrxnIdTimestamp( &$contribution ) {
        $parts = explode( ' ', $contribution['trxn_id'] );
        array_pop( $parts );
        $contribution['trxn_id'] = implode( ' ', $parts );
    }

    public function messageProvider() {
        return array(
            array(
                // Normal contribution
                array(
                    'email' => 'nobody@wikimedia.org',
                    'gross' => '1.23',
                    'currency' => 'USD',
                    'payment_method' => 'cc',
                    'gateway' => 'test_gateway',
                ),
                array(
                    'contribution_type_id' => '5',
                    'contribution_page_id' => '',
                    'payment_instrument_id' => '1',
                    'non_deductible_amount' => '',
                    'total_amount' => '1.23',
                    'fee_amount' => '0',
                    'net_amount' => '1.23',
                    'invoice_id' => '',
                    'currency' => 'USD',
                    'cancel_date' => '',
                    'cancel_reason' => '',
                    'receipt_date' => '',
                    'thankyou_date' => '',
                    'source' => 'USD 1.23',
                    'amount_level' => '',
                    'contribution_recur_id' => '',
                    'honor_contact_id' => '',
                    'is_test' => '',
                    'is_pay_later' => '',
                    'contribution_status_id' => '',
                    'honor_type_id' => '',
                    'address_id' => '',
                    'check_number' => 'null',
                    'campaign_id' => '',
                ),
            ),

            // Recurring contribution
            array(
                array(
                    'email' => 'nobody@wikimedia.org',
                    'gross' => $this->recur_amount,
                    'currency' => 'USD',
                    'payment_method' => 'cc',
                    'gateway' => 'test_gateway',
                    'contact_id' => $this->contact_id,
                    'contribution_recur_id' => $this->contribution_recur_id,
                    'effort_id' => 2,
                ),
                array(
                    'contact_id' => strval( $this->contact_id ),
                    'contribution_type_id' => '5',
                    'contribution_page_id' => '',
                    'payment_instrument_id' => '1',
                    'non_deductible_amount' => '',
                    'total_amount' => $this->recur_amount,
                    'fee_amount' => '0',
                    'net_amount' => $this->recur_amount,
                    'invoice_id' => '',
                    'currency' => 'USD',
                    'cancel_date' => '',
                    'cancel_reason' => '',
                    'receipt_date' => '',
                    'thankyou_date' => '',
                    'source' => 'USD ' . $this->recur_amount,
                    'amount_level' => '',
                    'contribution_recur_id' => strval( $this->contribution_recur_id ),
                    'honor_contact_id' => '',
                    'is_test' => '',
                    'is_pay_later' => '',
                    'contribution_status_id' => '',
                    'honor_type_id' => '',
                    'address_id' => '',
                    'check_number' => 'null',
                    'campaign_id' => '',
                ),
            ),
        );
    }
}

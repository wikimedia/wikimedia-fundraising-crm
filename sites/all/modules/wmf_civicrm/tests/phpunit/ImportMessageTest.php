<?php

class ImportMessageTest extends BaseWmfDrupalPhpUnitTestCase {
    protected $contribution_id;
    static protected $fixtures;

    public static function getInfo() {
        return array(
            'name' => 'Import Message',
            'group' => 'Pipeline',
            'description' => 'Attempt contribution message import.',
        );
    }

    public function tearDown() {
        if ( $this->contribution_id ) {
            civicrm_api_classapi()->Contribution->Delete( array(
                'id' => $this->contribution_id,
                'version' => '3',
            ) );
        }
        parent::tearDown();
    }

    /**
     * @dataProvider messageProvider
     */
    public function testMessageInsert( $msg, $expected ) {
        $contribution = wmf_civicrm_contribution_message_import( $msg );
        $this->contribution_id = $contribution['id'];

        $anonymized_contribution = $contribution;
        // Strip contact_id if we have no expectation.
        if ( empty( $expected['contribution']['contact_id'] ) ) {
            unset( $anonymized_contribution['contact_id'] );
        }
        unset( $anonymized_contribution['id'] );

        $this->assertEquals( $expected['contribution'], $anonymized_contribution );

        if ( !empty( $expected['contribution_custom_values'] ) ) {
            $actual_contribution_custom_values = wmf_civicrm_get_custom_values(
                $contribution['id'],
                array_keys( $expected['contribution_custom_values'] )
            );
            $this->assertEquals( $expected['contribution_custom_values'], $actual_contribution_custom_values );
        }

        if ( !empty( $expected['contact_custom_values'] ) ) {
            $actual_contact_custom_values = wmf_civicrm_get_custom_values(
                $contribution['contact_id'],
                array_keys( $expected['contact_custom_values'] )
            );
            $this->assertEquals( $expected['contact_custom_values'], $actual_contact_custom_values );
        }
    }

    public function messageProvider() {
        // Make static so it isn't destroyed until class cleanup.
        self::$fixtures = CiviFixtures::create();

        $contribution_type_cash = wmf_civicrm_get_civi_id( 'contribution_type_id', 'Cash' );
        $payment_instrument_cc = wmf_civicrm_get_civi_id( 'payment_instrument_id', 'Credit Card' );

        $gateway_txn_id = mt_rand();

        return array(
            // Minimal contribution
            array(
                array(
                    'currency' => 'USD',
                    'date' => '2012-05-01 00:00:00',
                    'email' => 'nobody@wikimedia.org',
                    'gateway' => 'test_gateway',
                    'gateway_txn_id' => $gateway_txn_id,
                    'gross' => '1.23',
                    'payment_method' => 'cc',
                ),
                array(
                    'contribution' => array(
                        'address_id' => '',
                        'amount_level' => '',
                        'campaign_id' => '',
                        'cancel_date' => '',
                        'cancel_reason' => '',
                        'check_number' => 'null',
                        'contribution_page_id' => '',
                        'contribution_recur_id' => '',
                        'contribution_status_id' => '',
                        'contribution_type_id' => $contribution_type_cash,
                        'currency' => 'USD',
                        'fee_amount' => '0',
                        'honor_contact_id' => '',
                        'honor_type_id' => '',
                        'invoice_id' => '',
                        'is_pay_later' => '',
                        'is_test' => '',
                        'net_amount' => '1.23',
                        'non_deductible_amount' => '',
                        'payment_instrument_id' => $payment_instrument_cc,
                        'receipt_date' => '',
                        'receive_date' => '20120501000000',
                        'source' => 'USD 1.23',
                        'thankyou_date' => '',
                        'total_amount' => '1.23',
                        'trxn_id' => "TEST_GATEWAY {$gateway_txn_id}",
                    ),
                ),
            ),

            // Maximal contribution
            array(
                array(
                    'currency' => 'USD',
                    'date' => '2012-03-01 00:00:00',
                    'do_not_solicit' => 'Y',
                    'email' => 'nobody@wikimedia.org',
                    'fee' => '0.03',
                    'gateway' => 'test_gateway',
                    'gateway_txn_id' => $gateway_txn_id,
                    'gross' => '1.23',
                    'no_thank_you' => 'no forwarding address',
                    'payment_method' => 'cc',
                    'thankyou_date' => '2012-04-01',
                ),
                array(
                    'contribution' => array(
                        'address_id' => '',
                        'amount_level' => '',
                        'campaign_id' => '',
                        'cancel_date' => '',
                        'cancel_reason' => '',
                        'check_number' => 'null',
                        'contribution_page_id' => '',
                        'contribution_recur_id' => '',
                        'contribution_status_id' => '',
                        'contribution_type_id' => $contribution_type_cash,
                        'currency' => 'USD',
                        'fee_amount' => '0.03',
                        'honor_contact_id' => '',
                        'honor_type_id' => '',
                        'invoice_id' => '',
                        'is_pay_later' => '',
                        'is_test' => '',
                        'net_amount' => '1.2', # :(
                        'non_deductible_amount' => '',
                        'payment_instrument_id' => $payment_instrument_cc,
                        'receipt_date' => '',
                        'receive_date' => '20120301000000',
                        'source' => 'USD 1.23',
                        'thankyou_date' => '20120401000000',
                        'total_amount' => '1.23',
                        'trxn_id' => "TEST_GATEWAY {$gateway_txn_id}",
                    ),
                    'contribution_custom_values' => array(
                        'gateway' => 'test_gateway',
                        'gateway_txn_id' => $gateway_txn_id,
                        'no_thank_you' => 'no forwarding address',
                    ),
                    'contact_custom_values' => array(
                        'do_not_solicit' => '1',
                        'is_2010_donor' => '0',
                        'is_2011_donor' => '1', # Fiscal year
                        'is_2012_donor' => '0',
                        'last_donation_date' => '2012-03-01 00:00:00',
                        'last_donation_usd' => '1.23',
                        'lifetime_usd_total' => '1.23',
                    ),
                ),
            ),

            // Subscription payment
            array(
                array(
                    'contact_id' => self::$fixtures->contact_id,
                    'contribution_recur_id' => self::$fixtures->contribution_recur_id,
                    'currency' => 'USD',
                    'date' => '2014-01-01 00:00:00',
                    'effort_id' => 2,
                    'email' => 'nobody@wikimedia.org',
                    'gateway' => 'test_gateway',
                    'gateway_txn_id' => $gateway_txn_id,
                    'gross' => self::$fixtures->recur_amount,
                    'payment_method' => 'cc',
                ),
                array(
                    'contribution' => array(
                        'address_id' => '',
                        'amount_level' => '',
                        'campaign_id' => '',
                        'cancel_date' => '',
                        'cancel_reason' => '',
                        'check_number' => 'null',
                        'contact_id' => strval( self::$fixtures->contact_id ),
                        'contribution_page_id' => '',
                        'contribution_recur_id' => strval( self::$fixtures->contribution_recur_id ),
                        'contribution_status_id' => '',
                        'contribution_type_id' => $contribution_type_cash,
                        'currency' => 'USD',
                        'fee_amount' => '0',
                        'honor_contact_id' => '',
                        'honor_type_id' => '',
                        'invoice_id' => '',
                        'is_pay_later' => '',
                        'is_test' => '',
                        'net_amount' => self::$fixtures->recur_amount,
                        'non_deductible_amount' => '',
                        'payment_instrument_id' => $payment_instrument_cc,
                        'receipt_date' => '',
                        'receive_date' => '20140101000000',
                        'source' => 'USD ' . self::$fixtures->recur_amount,
                        'thankyou_date' => '',
                        'total_amount' => self::$fixtures->recur_amount,
                        'trxn_id' => "TEST_GATEWAY {$gateway_txn_id}",
                    ),
                ),
            ),
        );
    }
}

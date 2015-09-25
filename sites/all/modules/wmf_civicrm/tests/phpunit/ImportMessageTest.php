<?php

/**
 * @group Import
 * @group Pipeline
 * @group WmfCivicrm
 */
class ImportMessageTest extends BaseWmfDrupalPhpUnitTestCase {
    protected $contact_custom_mangle;
    protected $contribution_id;
    protected $contribution_custom_mangle;
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

        if ( !empty( $expected['contact'] ) ) {
            $api = civicrm_api_classapi();
            $api->Contact->Get( array(
                'id' => $contribution['contact_id'],
                'version' => 3,
            ) );
            $contact = (array) $api->values[0];
            $this->assertEquals( $expected['contact'], array_intersect_key( $expected['contact'], $contact ) );
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
        $payment_instrument_check = wmf_civicrm_get_civi_id( 'payment_instrument_id', 'Check' );

        $gateway_txn_id = mt_rand();
        $check_number = (string) mt_rand();

        $new_prefix = 'M' . mt_rand();

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
                        'check_number' => '',
                        'contribution_page_id' => '',
                        'contribution_recur_id' => '',
                        'contribution_status_id' => '1',
                        'contribution_type_id' => $contribution_type_cash,
                        'currency' => 'USD',
                        'fee_amount' => '0',
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
                        'financial_type_id' => $contribution_type_cash,
                        'creditnote_id' => '',
                        'tax_amount' => '',
                    ),
                ),
            ),

            // Maximal contribution
            array(
                array(
                    'check_number' => $check_number,
                    'currency' => 'USD',
                    'date' => '2012-03-01 00:00:00',
                    'direct_mail_appeal' => 'mail code here',
                    'do_not_email' => 'Y',
                    'do_not_mail' => 'Y',
                    'do_not_phone' => 'Y',
                    'do_not_sms' => 'Y',
                    'do_not_solicit' => 'Y',
                    'email' => 'nobody@wikimedia.org',
                    'first_name' => 'First',
                    'fee' => '0.03',
                    'gateway' => 'test_gateway',
                    'gateway_txn_id' => $gateway_txn_id,
                    'gift_source' => 'Red mail',
                    'gross' => '1.23',
                    'import_batch_number' => '4321',
                    'is_opt_out' => 'Y',
                    'last_name' => 'Last',
                    'middle_name' => 'Middle',
                    'no_thank_you' => 'no forwarding address',
                    'name_prefix' => $new_prefix,
                    'name_suffix' => 'Sr.',
                    'payment_method' => 'check',
                    'stock_description' => 'Long-winded prolegemenon',
                    'thankyou_date' => '2012-04-01',
                ),
                array(
                    'contact' => array(
                        'do_not_email' => '1',
                        'do_not_mail' => '1',
                        'do_not_phone' => '1',
                        'do_not_sms' => '1',
                        'first_name' => 'First',
                        'is_opt_out' => '1',
                        'last_name' => 'Last',
                        'middle_name' => 'Middle',
                        'prefix' => $new_prefix,
                        'suffix' => 'Sr.',
                    ),
                    'contribution' => array(
                        'address_id' => '',
                        'amount_level' => '',
                        'campaign_id' => '',
                        'cancel_date' => '',
                        'cancel_reason' => '',
                        'check_number' => $check_number,
                        'contribution_page_id' => '',
                        'contribution_recur_id' => '',
                        'contribution_status_id' => '1',
                        'contribution_type_id' => $contribution_type_cash,
                        'currency' => 'USD',
                        'fee_amount' => '0.03',
                        'invoice_id' => '',
                        'is_pay_later' => '',
                        'is_test' => '',
                        'net_amount' => '1.2', # :(
                        'non_deductible_amount' => '',
                        'payment_instrument_id' => $payment_instrument_check,
                        'receipt_date' => '',
                        'receive_date' => '20120301000000',
                        'source' => 'USD 1.23',
                        'thankyou_date' => '20120401000000',
                        'total_amount' => '1.23',
                        'trxn_id' => "TEST_GATEWAY {$gateway_txn_id}",
                        'financial_type_id' => $contribution_type_cash,
                        'creditnote_id' => '',
                        'tax_amount' => '',
                    ),
                    'contribution_custom_values' => array(
                        'Appeal' => 'mail code here',
                        'import_batch_number' => '4321',
                        'Campaign' => 'Red mail',
                        'gateway' => 'test_gateway',
                        'gateway_txn_id' => (string) $gateway_txn_id,
                        'no_thank_you' => 'no forwarding address',
                        'Description_of_Stock' => 'Long-winded prolegemenon',
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

            // Organization contribution
            array(
                array(
                    'contact_type' => 'Organization',
                    'currency' => 'USD',
                    'date' => '2012-03-01 00:00:00',
                    'gateway' => 'test_gateway',
                    'gateway_txn_id' => $gateway_txn_id,
                    'gross' => '1.23',
                    'organization_name' => 'Hedgeco',
                    'org_contact_name' => 'Testname',
                    'org_contact_title' => 'Testtitle',
                    'payment_method' => 'cc',
                ),
                array(
                    'contribution' => array(
                        'address_id' => '',
                        'amount_level' => '',
                        'campaign_id' => '',
                        'cancel_date' => '',
                        'cancel_reason' => '',
                        'check_number' => '',
                        'contribution_page_id' => '',
                        'contribution_recur_id' => '',
                        'contribution_status_id' => '1',
                        'contribution_type_id' => $contribution_type_cash,
                        'currency' => 'USD',
			'fee_amount' => '0',
                        'invoice_id' => '',
                        'is_pay_later' => '',
                        'is_test' => '',
			'net_amount' => '1.23',
                        'non_deductible_amount' => '',
                        'payment_instrument_id' => $payment_instrument_cc,
                        'receipt_date' => '',
                        'receive_date' => '20120301000000',
                        'source' => 'USD 1.23',
                        'thankyou_date' => '',
                        'total_amount' => '1.23',
                        'trxn_id' => "TEST_GATEWAY {$gateway_txn_id}",
                        'financial_type_id' => $contribution_type_cash,
                        'creditnote_id' => '',
                        'tax_amount' => '',
                    ),
                    'contact_custom_values' => array(
                        'Name' => 'Testname',
                        'Title' => 'Testtitle',
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
                        'check_number' => '',
                        'contact_id' => strval( self::$fixtures->contact_id ),
                        'contribution_page_id' => '',
                        'contribution_recur_id' => strval( self::$fixtures->contribution_recur_id ),
                        'contribution_status_id' => '1',
                        'contribution_type_id' => $contribution_type_cash,
                        'currency' => 'USD',
                        'fee_amount' => '0',
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
                        'financial_type_id' => $contribution_type_cash,
                        'creditnote_id' => '',
                        'tax_amount' => '',
                    ),
                ),
            ),
        );
    }

    public function testImportContactGroups() {
        $fixtures = CiviFixtures::create();

        $msg = array(
            'currency' => 'USD',
            'date' => '2012-03-01 00:00:00',
            'gateway' => 'test_gateway',
            'gateway_txn_id' => mt_rand(),
            'gross' => '1.23',
            'payment_method' => 'cc',
            'contact_groups' => $fixtures->contact_group_name,
        );
        $contribution = wmf_civicrm_contribution_message_import( $msg );

        $api = civicrm_api_classapi();
        $api->GroupContact->Get( array(
            'contact_id' => $contribution['contact_id'],

            'version' => 3,
        ) );
        $this->assertEquals( 1, count( $api->values ) );
        $this->assertEquals( $fixtures->contact_group_id, $api->values[0]->group_id );
    }
}

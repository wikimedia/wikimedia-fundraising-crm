<?php

define( 'ImportMessageTest_campaign', 'test mail code here + ' . mt_rand() );

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

  /**
   * These are contribution fields that we do not check for in our comparison.
   *
   * Since we never set these always checking for them adds boilerplate code
   * and potential test breakiness.
   *
   * @var array
   */
    protected $fieldsToIgnore = array(
      'address_id',
      'contact_id',
      'cancel_date',
      'cancel_reason',
      'thankyou_date',
      'amount_level',
      'contribution_recur_id',
      'contribution_page_id',
      'creditnote_id',
      'is_test',
      'id',
      'invoice_id',
      'is_pay_later',
      'campaign_id',
      'tax_amount',
      'revenue_recognition_date',
    );

    protected $moneyFields = array(
      'total_amount',
      'source',
      'net_amount',
      'fee_amount',
    );

    public function setUp() {
        civicrm_api3( 'OptionValue', 'create', array(
            'option_group_id' => WMF_CAMPAIGNS_OPTION_GROUP_NAME,
            'label' => ImportMessageTest_campaign,
            'value' => ImportMessageTest_campaign,
        ) );
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

        // Ignore contact_id if we have no expectation.
        if ( empty( $expected['contribution']['contact_id'] ) ) {
            $this->fieldsToIgnore[] = 'contact_id';
        }

        $this->assertComparable( $expected['contribution'], $contribution );

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
            $renamedFields = array('prefix' => 1, 'suffix' => 1);
            $this->assertEquals( array_diff_key($expected['contact'], $renamedFields), array_intersect_key( $expected['contact'], $contact ) );
            foreach (array_keys($renamedFields) as $renamedField) {
                $this->assertEquals(civicrm_api3('OptionValue', 'getvalue', array(
                    'value' => $contact[$renamedField . '_id'],
                    'option_group_id' => 'individual_' . $renamedField,
                    'return' => 'name',
                )), $expected['contact'][$renamedField]);
            }
        }

		if ( !empty( $expected['address'] ) ) {
			$addresses = civicrm_api3( 'Address', 'get', array(
				'contact_id' => $contribution['contact_id'],
				'return' => 'city,postal_code,street_address,geo_code_1,geo_code_2,timezone',
			) );
			$address = $addresses['values'][$addresses['id']];
			$this->assertComparable( $expected['address'], $address );
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
                    'contribution' => $this->getBaseContribution($gateway_txn_id),
                ),
            ),

            // Minimal contribution with comma thousand separator.
            array(
                array(
                    'currency' => 'USD',
                    'date' => '2012-05-01 00:00:00',
                    'email' => 'nobody@wikimedia.org',
                    'gateway' => 'test_gateway',
                    'gateway_txn_id' => $gateway_txn_id,
                    'gross' => '1,000.23',
                    'payment_method' => 'cc',
                ),
                array(
                    'contribution' => array(
                        'contribution_status_id' => '1',
                        'contribution_type_id' => $contribution_type_cash,
                        'currency' => 'USD',
                        'fee_amount' => '0',
                        'total_amount' => '1,000.23',
                        'net_amount' => '1,000.23',
                        'non_deductible_amount' => '',
                        'payment_instrument_id' => $payment_instrument_cc,
                        'receipt_date' => '',
                        'receive_date' => '20120501000000',
                        'source' => 'USD 1,000.23',
                        'trxn_id' => "TEST_GATEWAY {$gateway_txn_id}",
                        'financial_type_id' => $contribution_type_cash,
                        'check_number' => '',
                    ),
                ),
            ),

            // Maximal contribution
            array(
                array(
                    'check_number' => $check_number,
                    'currency' => 'USD',
                    'date' => '2012-03-01 00:00:00',
                    'direct_mail_appeal' => ImportMessageTest_campaign,
                    'do_not_email' => 'Y',
                    'do_not_mail' => 'Y',
                    'do_not_phone' => 'Y',
                    'do_not_sms' => 'Y',
                    'do_not_solicit' => 'Y',
                    'email' => 'nobody@wikimedia.org',
                    'first_name' => 'First',
                    'fee' => '0.03',
                    'preferred_language' => 'en_US',
                    'gateway' => 'test_gateway',
                    'gateway_txn_id' => $gateway_txn_id,
                    'gateway_status' => 'P',
                    'gift_source' => 'Legacy Gift',
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
                        'preferred_language' => 'en_US',
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
                        'Appeal' => ImportMessageTest_campaign,
                        'import_batch_number' => '4321',
                        'Campaign' => 'Legacy Gift',
                        'gateway' => 'test_gateway',
                        'gateway_txn_id' => (string) $gateway_txn_id,
                        'gateway_status_raw' => 'P',
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
          // Invalid language suffix for valid short lang.
          'invalid language suffix' => array(
            array(
              'currency' => 'USD',
              'date' => '2012-05-01 00:00:00',
              'email' => 'nobody@wikimedia.org',
              'gateway' => 'test_gateway',
              'gateway_txn_id' => $gateway_txn_id,
              'gross' => '1.23',
              'payment_method' => 'cc',
              'preferred_language' => 'en_ZZ',
              'name_prefix' => $new_prefix,
              'name_suffix' => 'Sr.',
            ),
            array(
              'contact' => array(
                'preferred_language' => 'en',
                'prefix' => $new_prefix,
                'suffix' => 'Sr.',
              ),
              'contribution' => $this->getBaseContribution($gateway_txn_id),
            ),
          ),

          // Invalid language suffix for invalid short lang.
          'invalid short language' => array(
            array(
              'currency' => 'USD',
              'date' => '2012-05-01 00:00:00',
              'email' => 'nobody@wikimedia.org',
              'gateway' => 'test_gateway',
              'gateway_txn_id' => $gateway_txn_id,
              'gross' => '1.23',
              'payment_method' => 'cc',
              'preferred_language' => 'zz_ZZ',
              'name_prefix' => $new_prefix,
              'name_suffix' => 'Sr.',
              'prefix' => $new_prefix,
              'suffix' => 'Sr.',
            ),
            array(
              'contact' => array(
                'preferred_language' => 'zz_ZZ',
                'prefix' => $new_prefix,
                'suffix' => 'Sr.',
              ),
              'contribution' => $this->getBaseContribution($gateway_txn_id),
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
            // US address import is geocoded
            array(
                array(
                    'city' => 'Somerville',
                    'country' => 'US',
                    'currency' => 'USD',
                    'date' => '2012-05-01 00:00:00',
                    'email' => 'nobody@wikimedia.org',
                    'gateway' => 'test_gateway',
                    'gateway_txn_id' => $gateway_txn_id,
                    'gross' => '1.23',
                    'payment_method' => 'cc',
                    'postal_code' => '02144',
                    'state_province' => 'MA',
                    'street_address' => '1 Davis Square',
                ),
                array(
                    'contribution' => $this->getBaseContribution( $gateway_txn_id ),
                    'address' => array(
                        'city' => 'Somerville',
                        'postal_code' => '02144',
                        'street_address' => '1 Davis Square',
                        'geo_code_1' => '42.3995',
                        'geo_code_2' => '-71.1217',
                        'timezone' => 'UTC-5',
                    )
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

  /**
   * Assert that 2 arrays are the same in all the ways that matter :-).
   *
   * This has been written for a specific test & will probably take extra work
   * to use more broadly.
   *
   * @param array $array1
   * @param array $array2
   */
    public function assertComparable($array1, $array2) {
      $this->reformatMoneyFields($array1);
      $this->reformatMoneyFields($array2);
      $array1 = $this->filterIgnoredFieldsFromArray($array1);
      $array2 = $this->filterIgnoredFieldsFromArray($array2);
      $this->assertEquals($array1, $array2);

    }

  /**
   * Get the basic array of contribution data.
   *
   * @param string $gateway_txn_id
   *
   * @return array
   */
  protected function getBaseContribution($gateway_txn_id) {
    $contribution_type_cash = wmf_civicrm_get_civi_id( 'contribution_type_id', 'Cash' );
    $payment_instrument_cc = wmf_civicrm_get_civi_id( 'payment_instrument_id', 'Credit Card' );
    return array(
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
    );
  }

  /**
   * Remove commas from money fields.
   *
   * @param array $array
   */
    public function reformatMoneyFields(&$array) {
      foreach ($array as $field => $value) {
        if (in_array($field, $this->moneyFields)) {
          $array[$field] = str_replace(',', '', $value);
        }
      }
    }

  /**
   * Remove fields we don't care about from the array.
   *
   * @param array $array
   *
   * @return array
   */
    public function filterIgnoredFieldsFromArray($array) {
      return array_diff_key($array, array_flip($this->fieldsToIgnore));
    }

}

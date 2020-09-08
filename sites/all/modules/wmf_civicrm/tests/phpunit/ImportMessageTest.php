<?php

define('ImportMessageTest_campaign', 'test mail code here + ' . mt_rand());

/**
 * @group Import
 * @group Pipeline
 * @group WmfCivicrm
 */
class ImportMessageTest extends BaseWmfDrupalPhpUnitTestCase {

  protected $contact_custom_mangle;

  protected $contribution_id;

  protected $contact_id;

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
  protected $fieldsToIgnore = [
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
    'invoice_number',
    'is_template',
  ];

  protected $moneyFields = [
    'total_amount',
    'source',
    'net_amount',
    'fee_amount',
  ];

  public function setUp() {
    parent::setUp();
    wmf_civicrm_ensure_option_value_exists(WMF_CAMPAIGNS_OPTION_GROUP_NAME, ImportMessageTest_campaign);
    wmf_civicrm_ensure_correct_geocoder_enabled();
    $geoCoders = civicrm_api3('Geocoder', 'get', ['is_active' => 1]);
    $this->assertEquals(1, $geoCoders['count']);
  }

  public function tearDown() {
    if ($this->contribution_id) {
      $this->callAPISuccess('Contribution', 'delete', ['id' => $this->contribution_id]);
    }
    if ($this->contact_id) {
      $this->cleanUpContact($this->contact_id);
    }
    parent::tearDown();
  }

  /**
   * Test importing messages using variations form messagerProvider dataprovider.
   *
   * @dataProvider messageProvider
   *
   * @param array $msg
   * @param array $expected
   *
   * @throws \CRM_Core_Exception
   * @throws \CiviCRM_API3_Exception
   * @throws \WmfException
   */
  public function testMessageInsert($msg, $expected) {
    $contribution = wmf_civicrm_contribution_message_import($msg);
    $this->consumeCtQueue();
    $this->contribution_id = $contribution['id'];

    // Ignore contact_id if we have no expectation.
    if (empty($expected['contribution']['contact_id'])) {
      $this->fieldsToIgnore[] = 'contact_id';
    }

    $this->assertComparable($expected['contribution'], $contribution);

    if (!empty($expected['contribution_custom_values'])) {
      $actual_contribution_custom_values = wmf_civicrm_get_custom_values(
        $contribution['id'],
        array_keys($expected['contribution_custom_values'])
      );
      $this->assertEquals($expected['contribution_custom_values'], $actual_contribution_custom_values);
    }

    if (!empty($expected['contact'])) {
      $contact = $this->callAPISuccessGetSingle('Contact', ['id' => $contribution['contact_id']]);
      $renamedFields = ['prefix' => 1, 'suffix' => 1];
      $this->assertEquals(array_diff_key($expected['contact'], $renamedFields), array_intersect_key($contact, $expected['contact']), print_r(array_intersect_key($contact, $expected['contact']), TRUE) . " does not match " . print_r(array_diff_key($expected['contact'], $renamedFields), TRUE));
      foreach (array_keys($renamedFields) as $renamedField) {
        if (isset($expected['contact'][$renamedField])) {
          $this->assertEquals(civicrm_api3('OptionValue', 'getvalue', [
            'value' => $contact[$renamedField . '_id'],
            'option_group_id' => 'individual_' . $renamedField,
            'return' => 'name',
          ]), $expected['contact'][$renamedField]);
        }
      }
    }

    if (!empty($expected['address'])) {
      $addresses = civicrm_api3('Address', 'get', [
        'contact_id' => $contribution['contact_id'],
        'return' => 'country_id,state_province_id,city,postal_code,street_address,geo_code_1,geo_code_2,timezone',
      ]);
      $address = $addresses['values'][$addresses['id']];
      $this->assertComparable($expected['address'], $address);
    }

    if (!empty($expected['contact_custom_values'])) {
      $actual_contact_custom_values = wmf_civicrm_get_custom_values(
        $contribution['contact_id'],
        array_keys($expected['contact_custom_values'])
      );
      $this->assertEquals($expected['contact_custom_values'], $actual_contact_custom_values);
    }

    if (!empty($msg['contribution_tracking_id'])) {
      $tracking = db_select('contribution_tracking', 'contribution_tracking')
        ->fields('contribution_tracking')
        ->condition('contribution_id', $contribution['id'])
        ->execute()
        ->fetchAssoc();
      $this->assertEquals($tracking['id'], $msg['contribution_tracking_id']);
    }
  }

  /**
   * Data provider for import test.
   *
   * @return array
   *
   * @throws \WmfException
   */
  public function messageProvider(): array {
    // Make static so it isn't destroyed until class cleanup.
    self::$fixtures = CiviFixtures::create();

    $contribution_type_cash = (string) wmf_civicrm_get_civi_id('contribution_type_id', 'Cash');
    $payment_instrument_cc = (string) wmf_civicrm_get_civi_id('payment_instrument_id', 'Credit Card');
    $payment_instrument_check = (string) wmf_civicrm_get_civi_id('payment_instrument_id', 'Check');

    $gateway_txn_id = mt_rand();
    $check_number = (string) mt_rand();

    $new_prefix = 'M' . mt_rand();

    $cases = [
      // Minimal contribution
      [
        $this->getMinimalImportData($gateway_txn_id),
        [
          'contribution' => $this->getBaseContribution($gateway_txn_id),
        ],
      ],
    ];
    $gateway_txn_id = mt_rand();
    $cases[] =
      // Minimal contribution with comma thousand separator.
      [
        [
          'currency' => 'USD',
          'date' => '2012-05-01 00:00:00',
          'email' => 'nobody@wikimedia.org',
          'gateway' => 'test_gateway',
          'gateway_txn_id' => $gateway_txn_id,
          'gross' => '1,000.23',
          'payment_method' => 'cc',
        ],
        [
          'contribution' => [
            'contribution_status_id' => '1',
            'contribution_type_id' => $contribution_type_cash,
            'currency' => 'USD',
            'fee_amount' => '0.00',
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
          ],
        ],
      ];

    $gateway_txn_id = mt_rand();
    $cases[] =
      // over-long city.
      [
        array_merge(
          $this->getMinimalImportData($gateway_txn_id),
          ['city' => 'This is just stupidly long and I do not know why I would enter something this crazily long into a field']
        ),
        [
          'contribution' => $this->getBaseContribution($gateway_txn_id),
        ],
      ];

    $gateway_txn_id = mt_rand();
    $cases[] =
      // Maximal contribution
      [
        [
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
          'fee' => 0.03,
          'language' => 'en_US',
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
        ],
        [
          'contact' => [
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
            'preferred_language' => 'en',
          ],
          'contribution' => [
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
            'fee_amount' => 0.03,
            'invoice_id' => '',
            'is_pay_later' => '',
            'is_test' => '',
            'net_amount' => '1.20', # :(
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
          ],
          'contribution_custom_values' => [
            'Appeal' => ImportMessageTest_campaign,
            'import_batch_number' => '4321',
            'Campaign' => 'Legacy Gift',
            'gateway' => 'test_gateway',
            'gateway_txn_id' => (string) $gateway_txn_id,
            'gateway_status_raw' => 'P',
            'no_thank_you' => 'no forwarding address',
            'Description_of_Stock' => 'Long-winded prolegemenon',
          ],
          'contact_custom_values' => [
            'do_not_solicit' => '1',
            'total_2010' => 0,
            'total_2011' => 0,
            'total_2012' => 1.23,
            'number_donations' => 1,
            'first_donation_date' => '2012-03-01 00:00:00',
            'last_donation_date' => '2012-03-01 00:00:00',
            'last_donation_usd' => '1.23',
            'lifetime_usd_total' => '1.23',
            'total_2011_2012' => 1.23,
            'total_2010_2011' => 0,
            'total_2012_2013' => 0,
          ],
        ],
      ];

    $gateway_txn_id = mt_rand();
    $cases[] =
      // Invalid language suffix for valid short lang.
      [
        [
          'currency' => 'USD',
          'date' => '2012-05-01 00:00:00',
          'email' => 'nobody@wikimedia.org',
          'gateway' => 'test_gateway',
          'gateway_txn_id' => $gateway_txn_id,
          'gross' => '1.23',
          'payment_method' => 'cc',
          'language' => 'en_ZZ',
          'name_prefix' => $new_prefix,
          'name_suffix' => 'Sr.',
        ],
        [
          'contact' => [
            'preferred_language' => 'en',
            'prefix' => $new_prefix,
            'suffix' => 'Sr.',
          ],
          'contribution' => $this->getBaseContribution($gateway_txn_id),
        ],
      ];

    $gateway_txn_id = mt_rand();
    $cases[] =
      // Invalid language suffix for invalid short lang.
      [
        [
          'currency' => 'USD',
          'date' => '2012-05-01 00:00:00',
          'email' => 'nobody@wikimedia.org',
          'gateway' => 'test_gateway',
          'gateway_txn_id' => $gateway_txn_id,
          'gross' => '1.23',
          'payment_method' => 'cc',
          'language' => 'zz_ZZ',
          'name_prefix' => $new_prefix,
          'name_suffix' => 'Sr.',
          'prefix' => $new_prefix,
          'suffix' => 'Sr.',
        ],
        [
          'contact' => [
            'preferred_language' => 'zz',
            'prefix' => $new_prefix,
            'suffix' => 'Sr.',
          ],
          'contribution' => $this->getBaseContribution($gateway_txn_id),
        ],
      ];

    $gateway_txn_id = mt_rand();
    $cases[] =
      // Organization contribution
      [
        [
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
        ],
        [
          'contribution' => [
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
            'fee_amount' => '0.00',
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
          ],
          'contact_custom_values' => [
            'Name' => 'Testname',
            'Title' => 'Testtitle',
          ],
        ],
      ];

    $gateway_txn_id = mt_rand();
    $cases[] =
      // Subscription payment
      [
        [
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
        ],
        [
          'contribution' => [
            'address_id' => '',
            'amount_level' => '',
            'campaign_id' => '',
            'cancel_date' => '',
            'cancel_reason' => '',
            'check_number' => '',
            'contact_id' => (string) self::$fixtures->contact_id,
            'contribution_page_id' => '',
            'contribution_recur_id' => (string) self::$fixtures->contribution_recur_id,
            'contribution_status_id' => '1',
            'contribution_type_id' => $contribution_type_cash,
            'currency' => 'USD',
            'fee_amount' => '0.00',
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
          ],
        ],
      ];

    $gateway_txn_id = mt_rand();
    $cases[] =
      // Country-only address
      [
        array_merge(
          $this->getMinimalImportData($gateway_txn_id),
          [
            'country' => 'FR',
          ]
        ),
        [
          'contribution' => $this->getBaseContribution($gateway_txn_id),
          'address' => [
            'country_id' => wmf_civicrm_get_country_id('FR'),
          ],
        ],
      ];

    $gateway_txn_id = mt_rand();
    $cases[] =
      // Strip duff characters
      [
        array_merge(
          $this->getMinimalImportData($gateway_txn_id),
          [
            'first_name' => 'Baa   baa black sheep',
          ]
        ),
        [
          'contact' => [
            'first_name' => 'Baa baa black sheep',
          ],
          'contribution' => $this->getBaseContribution($gateway_txn_id),
        ],
      ];

    $gateway_txn_id = mt_rand();
    $cases[] = // white_space_cleanup
      [
        array_merge(
          $this->getMinimalImportData($gateway_txn_id),
          [
            // The multiple spaces & trailing ideographic space should go.
            // Internally I have set it to reduce multiple ideographic space to only one.
            // However, I've had second thoughts about my earlier update change to
            // convert them as they are formatted differently & the issue was not the
            // existence of them but the strings of several of them in a row.
            'first_name' => 'Baa   baa' . html_entity_decode('&#x3000;') . html_entity_decode(
                '&#x3000;'
              ) . 'black sheep' . html_entity_decode('&#x3000;'),
            'middle_name' => '  Have &nbsp; you any wool',
            'last_name' => ' Yes sir yes sir ' . html_entity_decode('&nbsp;') . ' three bags full',
          ]
        ),
        [
          'contact' => [
            'first_name' => 'Baa baa' . html_entity_decode('&#x3000;') . 'black sheep',
            'middle_name' => 'Have you any wool',
            'last_name' => 'Yes sir yes sir three bags full',
            'display_name' => 'Baa baa' . html_entity_decode(
                '&#x3000;'
              ) . 'black sheep Yes sir yes sir three bags full',
          ],
          'contribution' => $this->getBaseContribution($gateway_txn_id),
        ],
      ];
    $cases[] = // 'ampersands'
      [
        array_merge(
          $this->getMinimalImportData($gateway_txn_id),
          [
            // The multiple spaces & trailing ideographic space should go.
            // Internally I have set it to reduce multiple ideographic space to only one.
            // However, I've had second thoughts about my earlier update change to
            // convert them as they are formatted differently & the issue was not the
            // existance of them but the strings of several of them in a row.
            'first_name' => 'Jack &amp; Jill',
            'middle_name' => 'Jack &Amp; Jill',
            'last_name' => 'Jack & Jill',
          ]
        ),
        [
          'contact' => [
            'first_name' => 'Jack & Jill',
            'middle_name' => 'Jack & Jill',
            'last_name' => 'Jack & Jill',
            'display_name' => 'Jack & Jill Jack & Jill',
          ],
          'contribution' => $this->getBaseContribution($gateway_txn_id),
        ],
      ];

    $cases[] =
      // US address import is geocoded
      [
        [
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
        ],
        [
          'contribution' => $this->getBaseContribution($gateway_txn_id),
          'address' => [
            'country_id' => wmf_civicrm_get_country_id('US'),
            'state_province_id' => wmf_civicrm_get_state_id(
              wmf_civicrm_get_country_id('US'),
              'MA'
            ),
            'city' => 'Somerville',
            'postal_code' => '02144',
            'street_address' => '1 Davis Square',
            'geo_code_1' => '42.399546',
            'geo_code_2' => '-71.12165',
            'timezone' => 'UTC-5',
          ],
        ],
      ];

    $cases[] = // 'opt in (yes)'
      [
        array_merge(
          $this->getMinimalImportData($gateway_txn_id),
          [
            'opt_in' => '1',
          ]
        ),
        [
          'contact_custom_values' => [
            'opt_in' => '1',
          ],
          'contribution' => $this->getBaseContribution($gateway_txn_id),
        ],
      ];

    $cases[] = // 'opt in (no)'
      [
        array_merge(
          $this->getMinimalImportData($gateway_txn_id),
          [
            'opt_in' => '0',
          ]
        ),
        [
          'contact_custom_values' => [
            'opt_in' => '0',
          ],
          'contribution' => $this->getBaseContribution($gateway_txn_id),
        ],
      ];

    $cases[] = // 'opt in (empty)'
      [
        array_merge(
          $this->getMinimalImportData($gateway_txn_id),
          [
            'opt_in' => '',
          ]
        ),
        [
          'contact_custom_values' => [
            'opt_in' => NULL,
          ],
          'contribution' => $this->getBaseContribution($gateway_txn_id),
        ],
      ];

    $cases[] = // 'employer' field populated and mapped correctly
      [
        array_merge(
          $this->getMinimalImportData($gateway_txn_id),
          [
            'employer' => 'Wikimedia Foundation',
          ]
        ),
        [
          'contact_custom_values' => ['Employer_Name' => 'Wikimedia Foundation'],
          'contribution' => $this->getBaseContribution($gateway_txn_id),
        ],
      ];

    $gateway_txn_id = mt_rand();
    $endowmentFinancialType = (string) CRM_Core_PseudoConstant::getKey(
      'CRM_Contribute_BAO_Contribution', 'financial_type_id', 'Endowment Gift'
    );
    $cases[] = [ // Endowment Gift, specified in utm_medium
      [
        'currency' => 'USD',
        'date' => '2018-07-01 00:00:00',
        'email' => 'nobody@wikimedia.org',
        'first_name' => 'First',
        'fee' => '0.03',
        'language' => 'en_US',
        'gateway' => 'test_gateway',
        'gateway_txn_id' => $gateway_txn_id,
        'gateway_status' => 'P',
        'gross' => '1.23',
        'last_name' => 'Last',
        'middle_name' => 'Middle',
        'payment_method' => 'cc',
        'utm_medium' => 'endowment',
      ],
      [
        'contribution' => [
          'address_id' => '',
          'amount_level' => '',
          'campaign_id' => '',
          'cancel_date' => '',
          'cancel_reason' => '',
          'check_number' => '',
          'contribution_page_id' => '',
          'contribution_recur_id' => '',
          'contribution_status_id' => '1',
          'contribution_type_id' => $endowmentFinancialType,
          'currency' => 'USD',
          'fee_amount' => 0.03,
          'invoice_id' => '',
          'is_pay_later' => '',
          'is_test' => '',
          'net_amount' => '1.20', # :(
          'non_deductible_amount' => '',
          'payment_instrument_id' => $payment_instrument_cc,
          'receipt_date' => '',
          'receive_date' => '20180701000000',
          'source' => 'USD 1.23',
          'total_amount' => '1.23',
          'trxn_id' => "TEST_GATEWAY {$gateway_txn_id}",
          'financial_type_id' => $endowmentFinancialType,
          'creditnote_id' => '',
          'tax_amount' => '',
        ],
        'contribution_custom_values' => [
          'gateway' => 'test_gateway',
          'gateway_txn_id' => (string) $gateway_txn_id,
          'gateway_status_raw' => 'P',
        ],
      ],
    ];
    return $cases;
  }

  /**
   * Test importing to a group.
   *
   * @throws \CRM_Core_Exception
   * @throws \WmfException
   */
  public function testImportContactGroups() {
    $fixtures = CiviFixtures::create();

    $msg = [
      'currency' => 'USD',
      'date' => '2012-03-01 00:00:00',
      'gateway' => 'test_gateway',
      'gateway_txn_id' => mt_rand(),
      'gross' => '1.23',
      'payment_method' => 'cc',
      'contact_groups' => $fixtures->contact_group_name,
    ];
    $contribution = wmf_civicrm_contribution_message_import($msg);

    $group = $this->callAPISuccessGetSingle('GroupContact', ['contact_id' => $contribution['contact_id']]);
    $this->assertEquals($fixtures->contact_group_id, $group['group_id']);
  }

  /**
   * Test that existing on hold setting is retained.
   *
   * @throws \WmfException
   */
  public function testKeepOnHold() {
    self::$fixtures = CiviFixtures::create();
    $this->callAPISuccess('Email', 'create', [
      'email' => 'Agatha@wikimedia.org',
      'on_hold' => 1,
      'location_type_id' => 1,
      'contact_id' => self::$fixtures->contact_id,
    ]);

    $msg = [
      'contact_id' => self::$fixtures->contact_id,
      'contribution_recur_id' => self::$fixtures->contribution_recur_id,
      'currency' => 'USD',
      'date' => '2014-01-01 00:00:00',
      'effort_id' => 2,
      'email' => 'Agatha@wikimedia.org',
      'gateway' => 'test_gateway',
      'gateway_txn_id' => mt_rand(),
      'gross' => self::$fixtures->recur_amount,
      'payment_method' => 'cc',
    ];
    $contribution = wmf_civicrm_contribution_message_import($msg);
    $emails = $this->callAPISuccess('Email', 'get', [
      'contact_id' => self::$fixtures->contact_id,
      'sequential' => 1,
    ]);
    $this->assertEquals(1, $emails['count']);

    $this->assertEquals(1, $emails['values'][0]['on_hold']);
    $this->assertEquals('agatha@wikimedia.org', $emails['values'][0]['email']);

    $this->callAPISuccess('Contribution', 'delete', ['id' => $contribution['id']]);

  }

  /**
   * Test that existing on hold setting is removed if the email changes.
   *
   * @throws \WmfException
   */
  public function testRemoveOnHoldWhenUpdating() {
    self::$fixtures = CiviFixtures::create();
    $this->callAPISuccess('Email', 'create', [
      'email' => 'Agatha@wikimedia.org',
      'on_hold' => 1,
      'location_type_id' => 1,
      'contact_id' => self::$fixtures->contact_id,
    ]);

    $msg = [
      'contact_id' => self::$fixtures->contact_id,
      'contribution_recur_id' => self::$fixtures->contribution_recur_id,
      'currency' => 'USD',
      'date' => '2014-01-01 00:00:00',
      'effort_id' => 2,
      'email' => 'Pantha@wikimedia.org',
      'gateway' => 'test_gateway',
      'gateway_txn_id' => mt_rand(),
      'gross' => self::$fixtures->recur_amount,
      'payment_method' => 'cc',
    ];
    $contribution = wmf_civicrm_contribution_message_import($msg);
    $emails = $this->callAPISuccess('Email', 'get', [
      'contact_id' => self::$fixtures->contact_id,
      'sequential' => 1,
    ]);
    $this->assertEquals(1, $emails['count']);

    $this->assertEquals(0, $emails['values'][0]['on_hold']);
    $this->assertEquals('pantha@wikimedia.org', $emails['values'][0]['email']);

    $this->callAPISuccess('Contribution', 'delete', ['id' => $contribution['id']]);
  }


  public function testDuplicateHandling() {
    $fixtures = CiviFixtures::create();
    $error = NULL;
    $msg = [
      'currency' => 'USD',
      'date' => '2012-03-01 00:00:00',
      'gateway' => 'test_gateway',
      'order_id' => $fixtures->contribution_invoice_id,
      'gross' => '1.23',
      'payment_method' => 'cc',
      'gateway_txn_id' => 'CON_TEST_GATEWAY' . mt_rand(),
    ];
    $exceptioned = FALSE;
    try {
      wmf_civicrm_contribution_message_import($msg);
    }
    catch (WmfException $ex) {
      $exceptioned = TRUE;
      $this->assertTrue($ex->isRequeue());
      $this->assertEquals('DUPLICATE_INVOICE', $ex->getErrorName());
      $this->assertEquals(WmfException::DUPLICATE_INVOICE, $ex->getCode());
    }
    $this->assertTrue($exceptioned);
  }

  /**
   * When we get a contact ID and matching hash and email, update instead of
   * creating new contact.
   *
   * @throws \WmfException
   * @throws \CRM_Core_Exception
   */
  public function testImportWithContactIdAndHash() {
    $existingContact = $this->callAPISuccess('Contact', 'Create', [
      'contact_type' => 'Individual',
      'first_name' => 'Test',
      'last_name' => 'Es' . mt_rand(),
    ]);
    $this->contact_id = $existingContact['id'];
    $existingContact = $existingContact['values'][$existingContact['id']];
    $email = 'booboo' . mt_rand() . '@example.org';
    $this->callAPISuccess('Email', 'Create', [
      'contact_id' => $this->contact_id,
      'email' => $email,
      'location_type_id' => 1,
    ]);
    $this->callAPISuccess('Address', 'Create', [
      'contact_id' => $this->contact_id,
      'country' => wmf_civicrm_get_country_id('FR'),
      'street_address' => '777 Trompe L\'Oeil Boulevard',
      'location_type_id' => 1,
    ]);
    $msg = [
      'contact_id' => $existingContact['id'],
      'contact_hash' => $existingContact['hash'],
      'currency' => 'USD',
      'date' => '2017-01-01 00:00:00',
      'invoice_id' => mt_rand(),
      'country' => 'US',
      'street_address' => '123 42nd St. #321',
      'email' => $email,
      'gateway' => 'test_gateway',
      'gateway_txn_id' => mt_rand(),
      'gross' => '1.25',
      'payment_method' => 'cc',
    ];
    $contribution = wmf_civicrm_contribution_message_import($msg);
    $this->assertEquals($existingContact['id'], $contribution['contact_id']);
    $address = $this->callAPISuccessGetSingle(
      'Address', [
        'contact_id' => $existingContact['id'],
        'location_type' => 1,
      ]
    );
    $this->assertEquals($msg['street_address'], $address['street_address']);
  }

  /**
   * If we get a contact ID and a bad hash, leave the existing contact alone
   *
   * @throws \WmfException
   * @throws \CRM_Core_Exception
   */
  public function testImportWithContactIdAndBadHash() {
    $existingContact = $this->callAPISuccess('Contact', 'Create', [
      'contact_type' => 'Individual',
      'first_name' => 'Test',
      'last_name' => 'Es' . mt_rand(),
    ]);
    $email = 'booboo' . mt_rand() . '@example.org';
    $this->contact_id = $existingContact['id'];
    $existingContact = $existingContact['values'][$existingContact['id']];
    $this->callAPISuccess('Email', 'Create', [
      'contact_id' => $this->contact_id,
      'email' => $email,
      'location_type_id' => 1,
    ]);
    $this->callAPISuccess('Address', 'Create', [
      'contact_id' => $this->contact_id,
      'country' => wmf_civicrm_get_country_id('FR'),
      'street_address' => '777 Trompe L\'Oeil Boulevard',
      'location_type_id' => 1,
    ]);
    $msg = [
      'contact_id' => $existingContact['id'],
      'first_name' => 'Lex',
      'contact_hash' => 'This is not a valid hash',
      'currency' => 'USD',
      'date' => '2017-01-01 00:00:00',
      'invoice_id' => mt_rand(),
      'email' => $email,
      'country' => 'US',
      'street_address' => '123 42nd St. #321',
      'gateway' => 'test_gateway',
      'gateway_txn_id' => mt_rand(),
      'gross' => '1.25',
      'payment_method' => 'cc',
    ];
    $contribution = wmf_civicrm_contribution_message_import($msg);
    $this->assertNotEquals($existingContact['id'], $contribution['contact_id']);
    $address = $this->callAPISuccessGetSingle(
      'Address', [
        'contact_id' => $existingContact['id'],
        'location_type' => 1,
      ]
    );
    $this->assertNotEquals($msg['street_address'], $address['street_address']);
  }

  /**
   * If we get a contact ID and a bad email, leave the existing contact alone
   *
   * @throws \WmfException
   * @throws \CRM_Core_Exception
   */
  public function testImportWithContactIdAndBadEmail() {
    $existingContact = $this->callAPISuccess('Contact', 'Create', [
      'contact_type' => 'Individual',
      'first_name' => 'Test',
      'last_name' => 'Es' . mt_rand(),
    ]);
    $email = 'booboo' . mt_rand() . '@example.org';
    $this->contact_id = $existingContact['id'];
    $existingContact = $existingContact['values'][$existingContact['id']];
    $this->callAPISuccess('Email', 'Create', [
      'contact_id' => $this->contact_id,
      'email' => $email,
      'location_type_id' => 1,
    ]);
    $this->callAPISuccess('Address', 'Create', [
      'contact_id' => $this->contact_id,
      'country' => wmf_civicrm_get_country_id('FR'),
      'street_address' => '777 Trompe L\'Oeil Boulevard',
      'location_type_id' => 1,
    ]);
    $msg = [
      'contact_id' => $existingContact['id'],
      'first_name' => 'Lex',
      'contact_hash' => $existingContact['hash'],
      'currency' => 'USD',
      'date' => '2017-01-01 00:00:00',
      'invoice_id' => mt_rand(),
      'email' => 'totally.different@example.com',
      'country' => 'US',
      'street_address' => '123 42nd St. #321',
      'gateway' => 'test_gateway',
      'gateway_txn_id' => mt_rand(),
      'gross' => '1.25',
      'payment_method' => 'cc',
    ];
    $contribution = wmf_civicrm_contribution_message_import($msg);
    $this->assertNotEquals($existingContact['id'], $contribution['contact_id']);
    $address = $this->callAPISuccessGetSingle(
      'Address', [
        'contact_id' => $existingContact['id'],
        'location_type' => 1,
      ]
    );
    $this->assertNotEquals($msg['street_address'], $address['street_address']);
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

  protected function getMinimalImportData($gateway_txn_id): array {
    return [
      'currency' => 'USD',
      'date' => '2012-05-01 00:00:00',
      'email' => 'nobody@wikimedia.org',
      'gateway' => 'test_gateway',
      'gateway_txn_id' => $gateway_txn_id,
      'gross' => '1.23',
      'payment_method' => 'cc',
    ];
  }

  /**
   * Get the basic array of contribution data.
   *
   * @param string $gateway_txn_id
   *
   * @return array
   * @throws \WmfException
   */
  protected function getBaseContribution($gateway_txn_id): array {
    $contribution_type_cash = wmf_civicrm_get_civi_id('contribution_type_id', 'Cash');
    $payment_instrument_cc = (string) wmf_civicrm_get_civi_id('payment_instrument_id', 'Credit Card');
    return [
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
      'fee_amount' => '0.00',
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
    ];
  }

  /**
   * Remove commas from money fields.
   *
   * @param array $array
   */
  public function reformatMoneyFields(&$array) {
    foreach ($array as $field => $value) {
      if (in_array($field, $this->moneyFields, TRUE)) {
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
  public function filterIgnoredFieldsFromArray($array): array {
    return array_diff_key($array, array_flip($this->fieldsToIgnore));
  }

}

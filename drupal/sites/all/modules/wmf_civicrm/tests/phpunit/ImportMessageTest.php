<?php

use Civi\Api4\ContributionRecur;
use Civi\Api4\Contribution;
use Civi\Api4\Email;
use Civi\Api4\Relationship;
use Civi\WMFException\WMFException;
use wmf_civicrm\ImportStatsCollector;

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

  public function setUp(): void {
    parent::setUp();
    wmf_civicrm_ensure_option_value_exists(wmf_civicrm_get_direct_mail_field_option_id(), ImportMessageTest_campaign);
    wmf_civicrm_ensure_correct_geocoder_enabled();
    $geoCoders = civicrm_api3('Geocoder', 'get', ['is_active' => 1]);
    $this->assertEquals(1, $geoCoders['count']);
  }

  public function tearDown(): void {
    if ($this->contribution_id) {
      $this->callAPISuccess('Contribution', 'delete', ['id' => $this->contribution_id]);
    }
    if ($this->contact_id) {
      $this->cleanUpContact($this->contact_id);
    }
    ImportStatsCollector::tearDown(TRUE);
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
   * @throws \Civi\WMFException\WMFException
   */
  public function testMessageInsert($msg, $expected) {
    if (!empty($msg['contribution_recur_id'])) {
      // Create this here - the fixtures way was not reliable
      $msg['contact_id'] = $this->createIndividual();
      $msg['contribution_recur_id'] = $this->createRecurringContribution(['contact_id' => $msg['contact_id']]);
    }
    $contribution = $this->messageImport($msg);
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
   * Create a recurring contribution with some helpful defaults.
   *
   * @param array $params
   *
   * @return int
   */
  protected function createRecurringContribution($params = []): int {
    $this->ids['ContributionRecur']['import'] = ContributionRecur::create(FALSE)->setValues(array_merge([
      'amount' => '2.34',
      'currency' => 'USD',
      'frequency_unit' => 'month',
      'frequency_interval' => 1,
      'installments' => 0,
      'start_date' => '2018-06-20',
      'create_date' => '2018-06-20',
      'cancel_date' => null,
      'processor_id' => 1,
      'cycle_day' => 1,
      'trxn_id' => "RECURRING TEST_GATEWAY test" . mt_rand(0, 1000),

    ], $params))->execute()->first()['id'];
    return $this->ids['ContributionRecur']['import'];
  }

  /**
   * Create a contribution with some helpful defaults.
   *
   * @param array $params
   *
   * @return int
   */
  protected function createContribution($params = []) {
    return Contribution::create(FALSE)->setValues(array_merge([
      'total_amount' => '2.34',
      'currency' => 'USD',
      'receive_date' => '2018-06-20',
      'financial_type_id' => 1,
    ], $params))->execute()->first()['id'];
  }

  /**
   * Data provider for import test.
   *
   * @return array
   *
   * @throws \Civi\WMFException\WMFException
   */
  public function messageProvider(): array {

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
          'language' => 'en',
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
          'contact_id' => TRUE,
          'contribution_recur_id' => TRUE,
          'currency' => 'USD',
          'date' => '2014-01-01 00:00:00',
          'effort_id' => 2,
          'email' => 'nobody@wikimedia.org',
          'gateway' => 'test_gateway',
          'gateway_txn_id' => $gateway_txn_id,
          'gross' => 2.34,
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
            'contact_id' => TRUE,
            'contribution_page_id' => '',
            'contribution_recur_id' => TRUE,
            'contribution_status_id' => '1',
            'contribution_type_id' => $contribution_type_cash,
            'currency' => 'USD',
            'fee_amount' => '0.00',
            'invoice_id' => '',
            'is_pay_later' => '',
            'is_test' => '',
            'net_amount' => 2.34,
            'non_deductible_amount' => '',
            'payment_instrument_id' => $payment_instrument_cc,
            'receipt_date' => '',
            'receive_date' => '20140101000000',
            'source' => 'USD ' . 2.34,
            'thankyou_date' => '',
            'total_amount' => 2.34,
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

    $cases[] = // Language es-419
      [
        array_merge(
          $this->getMinimalImportData($gateway_txn_id),
          [
            'language' => 'es-419',
          ]
        ),
        [
          'contact' => [
            'preferred_language' => 'es_MX',
          ],
          'contribution' => $this->getBaseContribution($gateway_txn_id),
        ],
      ];
    return $cases;
  }

  /**
   * Test importing to a group.
   *
   * @throws \CRM_Core_Exception
   * @throws \Civi\WMFException\WMFException
   */
  public function testImportContactGroups(): void {
    $this->createGroup('in_group');
    $msg = [
      'currency' => 'USD',
      'date' => '2012-03-01 00:00:00',
      'gateway' => 'test_gateway',
      'gateway_txn_id' => mt_rand(),
      'gross' => '1.23',
      'payment_method' => 'cc',
      'contact_groups' => 'in_group',
    ];
    $contribution = $this->messageImport($msg);

    $group = $this->callAPISuccessGetSingle('GroupContact', ['contact_id' => $contribution['contact_id']]);
    $this->assertEquals($this->ids['Group']['in_group'], $group['group_id']);
  }

  /**
   * Create a group and add to cleanup tracking.
   *
   * @param string $name
   *
   * @return int
   */
  protected function createGroup(string $name): int {
    $group = civicrm_api3('Group', 'get', ['title' => $name]);

    if ($group['count'] === 1 ) {
      $this->ids['Group'][$name] = (int) $group['id'];
    }
    else {
      $group = civicrm_api3('Group', 'create', array(
        'title' => $name,
        'name' => $name,
      ));
      $this->ids['Group'][$name] = (int) $group['id'];
    }
    return $this->ids['Group'][$name];
  }

  /**
   * Test that existing on hold setting is retained.
   *
   * @throws \Civi\WMFException\WMFException
   */
  public function testKeepOnHold() {
    $contactID = $this->createIndividual();
    $this->callAPISuccess('Email', 'create', [
      'email' => 'Agatha@wikimedia.org',
      'on_hold' => 1,
      'location_type_id' => 1,
      'contact_id' => $contactID,
    ]);

    $msg = [
      'contact_id' => $contactID,
      'contribution_recur_id' => $this->createRecurringContribution(['contact_id' => $contactID]),
      'currency' => 'USD',
      'date' => '2014-01-01 00:00:00',
      'effort_id' => 2,
      'email' => 'Agatha@wikimedia.org',
      'gateway' => 'test_gateway',
      'gateway_txn_id' => mt_rand(),
      'gross' => 2.34,
      'payment_method' => 'cc',
    ];
    $contribution = wmf_civicrm_contribution_message_import($msg);
    $emails = $this->callAPISuccess('Email', 'get', [
      'contact_id' => $contactID,
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
   * @throws \Civi\WMFException\WMFException
   */
  public function testRemoveOnHoldWhenUpdating() {
    $contactID = $this->createIndividual();
    $this->callAPISuccess('Email', 'create', [
      'email' => 'Agatha@wikimedia.org',
      'on_hold' => 1,
      'location_type_id' => 1,
      'contact_id' => $contactID,
    ]);

    $msg = [
      'contact_id' => $contactID,
      'contribution_recur_id' => $this->createRecurringContribution(['contact_id' => $contactID]),
      'currency' => 'USD',
      'date' => '2014-01-01 00:00:00',
      'effort_id' => 2,
      'email' => 'Pantha@wikimedia.org',
      'gateway' => 'test_gateway',
      'gateway_txn_id' => mt_rand(),
      'gross' => 2.34,
      'payment_method' => 'cc',
    ];
    $contribution = $this->messageImport($msg);
    $emails = $this->callAPISuccess('Email', 'get', [
      'contact_id' => $contactID,
      'sequential' => 1,
    ]);
    $this->assertEquals(1, $emails['count']);

    $this->assertEquals(0, $emails['values'][0]['on_hold']);
    $this->assertEquals('pantha@wikimedia.org', $emails['values'][0]['email']);

    $this->callAPISuccess('Contribution', 'delete', ['id' => $contribution['id']]);
  }

  public function testDuplicateHandling() {
    $error = NULL;
    $invoiceID = mt_rand(0, 1000);
    $this->createContribution(['contact_id' => $this->createIndividual(), 'invoice_id' => $invoiceID]);
    $msg = [
      'currency' => 'USD',
      'date' => '2012-03-01 00:00:00',
      'gateway' => 'test_gateway',
      'order_id' => $invoiceID,
      'gross' => '1.23',
      'payment_method' => 'cc',
      'gateway_txn_id' => 'CON_TEST_GATEWAY' . mt_rand(),
    ];

    $exceptioned = FALSE;
    try {
      $this->messageImport($msg);
    }
    catch (WMFException $ex) {
      $exceptioned = TRUE;
      $this->assertTrue($ex->isRequeue());
      $this->assertEquals('DUPLICATE_INVOICE', $ex->getErrorName());
      $this->assertEquals(WMFException::DUPLICATE_INVOICE, $ex->getCode());
    }
    $this->assertTrue($exceptioned);
  }

  /**
   * When we get a contact ID and matching hash and email, update instead of
   * creating new contact.
   *
   * @throws \Civi\WMFException\WMFException
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
    $expectedEmployer = "Subotnik's Apple Orchard";
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
      'employer' => $expectedEmployer,
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
    $employerField = 'custom_' . CRM_Core_BAO_CustomField::getCustomFieldID('Employer Name');
    $contact = $this->callAPISuccessGetSingle(
      'Contact', [
        'id' => $existingContact['id'],
        'return' => $employerField,
      ]
    );
    $this->assertEquals($expectedEmployer, $contact[$employerField]);
  }

  /**
   * If we get a contact ID and a bad hash, leave the existing contact alone
   *
   * @throws \Civi\WMFException\WMFException
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
    $contribution = $this->messageImport($msg);
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
   * @throws \Civi\WMFException\WMFException
   * @throws \CRM_Core_Exception
   */
  public function testImportWithContactExisting() {
    $existingContact = $this->callAPISuccess('Contact', 'Create', [
      'contact_type' => 'Individual',
      'first_name' => 'Test',
      'last_name' => 'Dupey',
      'email' => 'dupe@example.org',
    ]);

    $this->contact_id = $existingContact['id'];

    $msg = [
      'first_name' => 'Test',
      'last_name' => 'Dupey',
      'currency' => 'USD',
      'date' => '2017-01-01 00:00:00',
      'invoice_id' => mt_rand(),
      'email' => 'dupe@example.org',
      'country' => 'US',
      'street_address' => '123 42nd St. #321',
      'gateway' => 'test_gateway',
      'gateway_txn_id' => mt_rand(),
      'gross' => '1.25',
      'payment_method' => 'cc',
    ];
    $contribution = wmf_civicrm_contribution_message_import($msg);
    $this->assertEquals($existingContact['id'], $contribution['contact_id']);
  }

  /**
   * If we get a contact ID and a bad email, leave the existing contact alone
   *
   * @throws \Civi\WMFException\WMFException
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
    $contribution = $this->messageImport($msg);
    $this->assertNotEquals($existingContact['id'], $contribution['contact_id']);
    $address = $this->callAPISuccessGetSingle(
      'Address', [
        'contact_id' => $existingContact['id'],
        'location_type' => 1,
      ]
    );
    $this->assertNotEquals($msg['street_address'], $address['street_address']);
  }

  public function testRecurringNoToken() {
    // need to set up a recurring message recurring=1 but there is no entry in the token DB
    $msg = [
      'first_name' => 'Lex',
      'currency' => 'USD',
      'date' => '2017-01-01 00:00:00',
      'invoice_id' => mt_rand(),
      'email' => 'totally.different@example.com',
      'country' => 'US',
      'street_address' => '123 42nd St. #321',
      'gateway' => 'Ingenico',
      'gateway_txn_id' => mt_rand(),
      'gross' => '1.25',
      'payment_method' => 'cc',
      'recurring' => 1,
      'recurring_payment_token' => mt_rand(),
      'user_ip' => '123.232.232'
    ];
    $contribution = $this->messageImport($msg);

  }

  /**
   * @see https://phabricator.wikimedia.org/T262232
   */
  public function testInvalidZipCodeDataFiltered() {
    $createContact = $this->callAPISuccess('Contact', 'Create', [
      'contact_type' => 'Individual',
      'first_name' => 'Test',
      'last_name' => 'Es' . mt_rand(),
    ]);
    $contact = $createContact['values'][$createContact['id']];
    $this->contact_id = $contact['id'];

    $msg = [
      'contact_id' =>$contact['id'],
      'contact_hash' => $contact['hash'],
      'currency' => 'USD',
      'date' => time(),
      'gateway' => 'test_gateway',
      'gateway_txn_id' => mt_rand(),
      'gross' => '1.23',
      'payment_method' => 'cc',
      'street_address' => '1 Montgomery Street',
      'city' => 'San Francisco',
      'state_province' => 'CA',
      'country' => 'US',
      'postal_code' => '9412”£&*1', // Problematic postal code
    ];

    $contribution = wmf_civicrm_contribution_message_import( $msg );
    $this->contribution_id = $contribution['id'];

    $address = $this->callAPISuccessGetSingle(
      'Address',
      ['contact_id' => $contact['id']]
    );

    $this->assertEquals("94121", $address['postal_code']);
  }

  /**
   * @dataProvider employerRelationDataProvider
   * @param string $sourceType
   * @param bool $isUpdate
   * @param ?bool $expected
   * @throws API_Exception
   * @throws CiviCRM_API3_Exception
   * @throws WMFException
   * @throws \Civi\API\Exception\UnauthorizedException
   */
  public function testIndicatesEmployerProvidedByDonor(string $sourceType, bool $isUpdate, ?bool $expected) {
    $orgContact = $this->callAPISuccess('Contact', 'create', array(
      'organization_name' => 'Puritan Foods',
      'contact_type' => 'Organization',
    ));
    $this->ids['Contact'][] = $orgContact['id'];

    $contactParams = [
      'first_name' => 'Philip',
      'last_name' => 'Mason',
    ];
    if ($isUpdate) {
      $existingContact = $this->callAPISuccess(
        'Contact', 'Create', array_merge($contactParams, [
          'contact_type' => 'Individual',
          'employer_id' => $orgContact['id'],
        ])
      );
      Email::create(FALSE)
        ->setValues([
          'contact_id' => $existingContact['id'],
          'email' => 'pmason@puritanfoods.com',
        ])
        ->execute();
    }

    $msg = array_merge(
      $contactParams, $this->getMinimalImportData(mt_rand())
    );
    $msg['email'] = 'pmason@puritanfoods.com';
    $msg['source_type'] = $sourceType;
    $msg['employer_id'] = $orgContact['id'];

    $contribution = wmf_civicrm_contribution_message_import($msg);
    $this->ids['Contact'][] = $contribution['contact_id'];

    $relationship = Relationship::get(FALSE)
      ->addWhere('contact_id_a', '=', $contribution['contact_id'])
      ->addWhere('contact_id_b', '=', $orgContact['id'])
      ->addWhere('relationship_type_id:name', '=', 'Employee of')
      ->addWhere('is_active', '=', 1)
      ->addSelect('custom.*')
      ->execute();

    $this->assertCount(1, $relationship);
    $this->assertEquals(
      $expected, $relationship->first()['Relationship_Metadata.provided_by_donor']
    );

    $contactOrgName = $this->callAPISuccessGetValue('Contact', [
      'return' => 'current_employer',
      'id' => $contribution['contact_id'],
    ]);
    $this->assertEquals('Puritan Foods', $contactOrgName);
    // TODO: test with active relationship to other employer
  }

  /**
   * Data provider for employer metadata tests
   * @return array[]
   */
  public function employerRelationDataProvider(): array {
    return [
      // Should create new donor with employer, provided_by_donor = TRUE
      ['payments', FALSE, TRUE],
      // Should update donor with employer relationship, provided_by_donor = TRUE
      ['payments', TRUE, TRUE],
      // Should create new donor with employer, provided_by_donor not set
      ['direct', FALSE, NULL],
      // Should update donor with employer relationship, provided_by_donor not set
      ['direct', TRUE, NULL],
    ];
  }

  /**
   * @dataProvider messageProvider
   *
   * @param array $msg
   * @param array $expected
   *
   * @throws \CRM_Core_Exception
   * @throws \CiviCRM_API3_Exception
   * @throws \Civi\WMFException\WMFException
   */
  public function testMessageImportStatsCreatedOnImport($msg, $expected) {
    if (!empty($msg['contribution_recur_id'])) {
      $msg['contact_id'] = $this->createIndividual();
      $msg['contribution_recur_id'] = $this->createRecurringContribution(['contact_id' => $msg['contact_id']]);
    }

    $importStatsCollector = ImportStatsCollector::getInstance();
    $emptyStats = $importStatsCollector->getAllStats();
    $this->assertEmpty($emptyStats);

    $this->messageImport($msg);

    $importStatsCollector = ImportStatsCollector::getInstance();
    $notEmptyStats = $importStatsCollector->getAllStats();
    $this->assertNotEmpty($notEmptyStats);
  }

  /**
   * @dataProvider messageProvider
   *
   * @param array $msg
   * @param array $expected
   *
   * @throws \CRM_Core_Exception
   * @throws \CiviCRM_API3_Exception
   * @throws \Civi\WMFException\WMFException
   */
  public function testMessageImportStatsProcessingRatesGenerated(
    $msg,
    $expected
  ) {
    if (!empty($msg['contribution_recur_id'])) {
      $msg['contact_id'] = $this->createIndividual();
      $msg['contribution_recur_id'] = $this->createRecurringContribution(['contact_id' => $msg['contact_id']]);
    }

    $importStatsCollector = ImportStatsCollector::getInstance();
    $emptyStats = $importStatsCollector->getAllStats();
    $this->assertEmpty($emptyStats);

    $this->messageImport($msg);

    // Ignore contact_id if we have no expectation.
    if (empty($expected['contribution']['contact_id'])) {
      $this->fieldsToIgnore[] = 'contact_id';
    }

    //check we have running times for a insertContribution after each import
    $contribution_insert_stats = $importStatsCollector->get("*timer.message_contribution_insert*");

    $this->assertArrayHasKey('start', $contribution_insert_stats);
    $this->assertArrayHasKey('end', $contribution_insert_stats);
    $this->assertArrayHasKey('diff', $contribution_insert_stats);
  }

  /**
   * Test that no errors are thrown when an ImportStatsCollector
   * timer is started twice for the same stat.
   *
   * Previously this would fail and convert the 'start' stat into an
   * array but now we protect against this by disregrding any existing
   * start timestamps for timers that are started again.
   *
   * @see https://phabricator.wikimedia.org/T289175
   */
  public function testMessageImportStatsResetStartTimer() {
    $this->markTestSkipped('flapping');
    $importStatsCollector = ImportStatsCollector::getInstance();
    $emptyStats = $importStatsCollector->getAllStats();
    $this->assertEmpty($emptyStats);

    // call start timer the first time
    $importStatsCollector->startImportTimer("important_import_process");
    // call start timer the second time on the same stat
    $importStatsCollector->startImportTimer("important_import_process");
    $importStatsCollector->endImportTimer("important_import_process");

    // check we have processing times for our timer stat
    $contribution_insert_stats = $importStatsCollector->get("*timer.important_import_process*");
    // there should be two stats, the orphaned partial first timer stat and the second complete timer stat
    $this->assertCount(2, $contribution_insert_stats);

    $orphaned_first_timer = $contribution_insert_stats[0];
    $second_timer = $contribution_insert_stats[1];

    $this->assertArrayHasKey('start', $orphaned_first_timer);
    $this->assertArrayNotHasKey('end', $orphaned_first_timer);
    $this->assertArrayNotHasKey('diff', $orphaned_first_timer);

    $this->assertArrayHasKey('start', $second_timer);
    $this->assertArrayHasKey('end', $second_timer);
    $this->assertArrayHasKey('diff', $second_timer);
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
   * @throws \Civi\WMFException\WMFException
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

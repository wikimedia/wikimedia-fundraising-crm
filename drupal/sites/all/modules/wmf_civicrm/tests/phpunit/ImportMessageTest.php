<?php

use Civi\Api4\ContributionRecur;
use Civi\Api4\Contribution;
use Civi\Api4\Email;
use Civi\Api4\Relationship;
use Civi\WMFException\WMFException;
use Civi\WMFStatistic\ImportStatsCollector;
use Statistics\Exception\StatisticsCollectorException;

define('ImportMessageTest_campaign', 'test mail code here + ' . mt_rand());

/**
 * @group Import
 * @group Pipeline
 * @group WmfCivicrm
 */
class ImportMessageTest extends BaseWmfDrupalPhpUnitTestCase {

  /**
   * These are contribution fields that we do not check for in our comparison.
   *
   * Since we never set these always checking for them adds boilerplate code
   * and potential test breakiness.
   *
   * @var array
   */
  protected array $fieldsToIgnore = [
    'address_id',
    'contact_id',
    'cancel_date',
    'cancel_reason',
    'thankyou_date',
    'amount_level',
    'contribution_recur_id',
    'contribution_page_id',
    'is_test',
    'id',
    'invoice_id',
    'is_pay_later',
    'campaign_id',
    'revenue_recognition_date',
    'invoice_number',
    'is_template',
  ];

  public function setUp(): void {
    parent::setUp();
    wmf_civicrm_ensure_option_value_exists(wmf_civicrm_get_direct_mail_field_option_id(), ImportMessageTest_campaign);
    $geoCoders = civicrm_api3('Geocoder', 'get', ['is_active' => 1]);
    $this->assertEquals(1, $geoCoders['count']);
  }

  /**
   * Test importing messages using variations form messageProvider data-provider.
   *
   * @dataProvider messageProvider
   *
   * @param array $msg
   * @param array $expected
   *
   * @throws CRM_Core_Exception
   */
  public function testMessageInsert(array $msg, array $expected): void {
    if (!empty($msg['contribution_recur_id'])) {
      // Create this here - the fixtures way was not reliable
      $msg['contact_id'] = $this->createIndividual();
      $msg['contribution_recur_id'] = $this->createRecurringContribution(['contact_id' => $msg['contact_id']]);
    }
    $this->processMessage($msg, 'Donation', 'test');
    $contribution = $this->getContributionForMessage($msg);
    $this->processContributionTrackingQueue();

    // Ignore contact_id if we have no expectation.
    if (empty($expected['contribution']['contact_id'])) {
      $this->fieldsToIgnore[] = 'contact_id';
    }

    $this->assertComparable($expected['contribution'], $contribution);

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
  }

  /**
   * Create a recurring contribution with some helpful defaults.
   *
   * @param array $params
   *
   * @return int
   * @throws CRM_Core_Exception
   */
  protected function createRecurringContribution(array $params = []): int {
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
  protected function createContribution($params = []): int {
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
   */
  public function messageProvider(): array {

    $financial_type_cash = (int) \CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_Contribution', 'financial_type_id', 'Cash');
    $payment_instrument_cc = (int) \CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_Contribution', 'payment_instrument_id', 'Credit Card: Visa');
    $payment_instrument_check = (int) \CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_Contribution', 'payment_instrument_id', 'Check');

    $gateway_txn_id = mt_rand();
    $check_number = (string) mt_rand();

    $cases = [
      'Minimal contribution' => [
        $this->getMinimalImportData($gateway_txn_id),
        [
          'contribution' => $this->getBaseContribution($gateway_txn_id),
        ],
      ],
    ];
    $gateway_txn_id = mt_rand();
    $cases['Minimal contribution with comma thousand separator'] = [
      [
        'currency' => 'USD',
        'date' => '2012-05-01 00:00:00',
        'email' => 'mouse@wikimedia.org',
        'gateway' => 'test_gateway',
        'gateway_txn_id' => $gateway_txn_id,
        'gross' => '1,000.23',
        'payment_method' => 'cc',
        'payment_submethod' => 'visa',
      ],
      [
        'contribution' => [
          'contribution_status_id' => '1',
          'currency' => 'USD',
          'fee_amount' => 0.00,
          'total_amount' => '1,000.23',
          'net_amount' => '1,000.23',
          'payment_instrument_id' => $payment_instrument_cc,
          'receipt_date' => '',
          'receive_date' => '2012-05-01 00:00:00',
          'source' => 'USD 1,000.23',
          'trxn_id' => "TEST_GATEWAY {$gateway_txn_id}",
          'financial_type_id' => $financial_type_cash,
          'check_number' => '',
        ],
      ],
    ];

    $gateway_txn_id = mt_rand();
    $cases['over-long city'] = [
      array_merge(
        $this->getMinimalImportData($gateway_txn_id),
        ['city' => 'This is just stupidly long and I do not know why I would enter something this crazily long into a field']
      ),
      [
        'contribution' => $this->getBaseContribution($gateway_txn_id),
      ],
    ];

    $gateway_txn_id = mt_rand();
    $cases['Maximal contribution'] = [
      [
        'check_number' => $check_number,
        'currency' => 'USD',
        'date' => '2024-03-01 00:00:00',
        'direct_mail_appeal' => ImportMessageTest_campaign,
        'do_not_email' => 'Y',
        'do_not_mail' => 'Y',
        'do_not_phone' => 'Y',
        'do_not_sms' => 'Y',
        'do_not_solicit' => 'Y',
        'email' => 'mouse@wikimedia.org',
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
        'name_prefix' => 'Mr.',
        'name_suffix' => 'Sr.',
        'payment_method' => 'check',
        'stock_description' => 'Long-winded prolegemenon',
        'thankyou_date' => '2024-04-01',
        'fiscal_number' => 'AAA11223344',
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
          'prefix' => 'Mr.',
          'suffix' => 'Sr.',
          'preferred_language' => 'en_US',
          'legal_identifier' => 'AAA11223344',
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
          'currency' => 'USD',
          'fee_amount' => 0.03,
          'invoice_id' => '',
          'is_pay_later' => '',
          'is_test' => '',
          'net_amount' => 1.20,
          'payment_instrument_id' => $payment_instrument_check,
          'receipt_date' => '',
          'receive_date' => '2024-03-01 00:00:00',
          'source' => 'USD 1.23',
          'thankyou_date' => '2024-04-01 00:00:00',
          'total_amount' => '1.23',
          'trxn_id' => "TEST_GATEWAY {$gateway_txn_id}",
          'financial_type_id' => $financial_type_cash,
          'Gift_Data.Appeal' => ImportMessageTest_campaign,
          'Gift_Information.import_batch_number' => '4321',
          'Gift_Data.Campaign' => 'Legacy Gift',
          'contribution_extra.gateway' => 'test_gateway',
          'contribution_extra.gateway_txn_id' => (string) $gateway_txn_id,
          'contribution_extra.gateway_status_raw' => 'P',
          'contribution_extra.no_thank_you' => 'no forwarding address',
          'Stock_Information.Description_of_Stock' => 'Long-winded prolegemenon',
        ],
        'contact_custom_values' => [
          'do_not_solicit' => '1',
          'total_2023' => 0,
          'total_2024' => 1.23,
          'number_donations' => 1,
          'first_donation_date' => '2024-03-01 00:00:00',
          'last_donation_date' => '2024-03-01 00:00:00',
          'last_donation_usd' => '1.23',
          'lifetime_usd_total' => '1.23',
          'total_2023_2024' => 1.23,
        ],
      ],
    ];

    $gateway_txn_id = mt_rand();
    $cases['Invalid language suffix for valid short lang'] = [
      [
        'currency' => 'USD',
        'date' => '2012-05-01 00:00:00',
        'email' => 'mouse@wikimedia.org',
        'gateway' => 'test_gateway',
        'gateway_txn_id' => $gateway_txn_id,
        'gross' => '1.23',
        'payment_method' => 'cc',
        'payment_submethod' => 'visa',
        'language' => 'en_ZW',
        'name_prefix' => 'Mr.',
        'name_suffix' => 'Sr.',
      ],
      [
        'contact' => [
          'preferred_language' => 'en_US',
          'prefix' => 'Mr.',
          'suffix' => 'Sr.',
        ],
        'contribution' => $this->getBaseContribution($gateway_txn_id),
      ],
    ];

    $gateway_txn_id = mt_rand();
    $cases['Full name'] = [
      [
        'currency' => 'USD',
        'date' => '2012-05-01 00:00:00',
        'email' => 'mouse@wikimedia.org',
        'gateway' => 'test_gateway',
        'gateway_txn_id' => $gateway_txn_id,
        'gross' => '1.23',
        'payment_method' => 'cc',
        'payment_submethod' => 'visa',
        'language' => 'en_US',
        'full_name' => 'Dr. Martin Luther Mouse, Jr.',
      ],
      [
        'contact' => [
          'prefix' => 'Dr.',
          'first_name' => 'Martin',
          'middle_name' => 'Luther',
          'last_name' => 'Mouse',
          'suffix' => 'Jr',
        ],
        'contribution' => $this->getBaseContribution($gateway_txn_id),
      ],
    ];
    $gateway_txn_id = mt_rand();
    $cases['Organization contribution'] = [
      [
        'contact_type' => 'Organization',
        'currency' => 'USD',
        'date' => '2012-03-01 00:00:00',
        'gateway' => 'test_gateway',
        'gateway_txn_id' => $gateway_txn_id,
        'gross' => '1.23',
        'organization_name' => 'The Firm',
        'org_contact_name' => 'Test Name',
        'org_contact_title' => 'Test Title',
        'payment_method' => 'cc',
        'payment_submethod' => 'visa',
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
          'currency' => 'USD',
          'fee_amount' => 0.00,
          'invoice_id' => '',
          'is_pay_later' => '',
          'is_test' => '',
          'net_amount' => '1.23',
          'payment_instrument_id' => $payment_instrument_cc,
          'receipt_date' => '',
          'receive_date' => '2012-03-01 00:00:00',
          'source' => 'USD 1.23',
          'thankyou_date' => '',
          'total_amount' => '1.23',
          'trxn_id' => "TEST_GATEWAY {$gateway_txn_id}",
          'financial_type_id' => $financial_type_cash,
        ],
        'contact_custom_values' => [
          'Name' => 'Test Name',
          'Title' => 'Test Title',
        ],
      ],
    ];

    $gateway_txn_id = mt_rand();
    $cases['Subscription payment'] = [
      [
        'contact_id' => TRUE,
        'contribution_recur_id' => TRUE,
        'currency' => 'USD',
        'date' => '2014-01-01 00:00:00',
        'effort_id' => 2,
        'email' => 'mouse@wikimedia.org',
        'gateway' => 'test_gateway',
        'gateway_txn_id' => $gateway_txn_id,
        'gross' => 2.34,
        'payment_method' => 'cc',
        'payment_submethod' => 'visa',
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
          'currency' => 'USD',
          'fee_amount' => 0.00,
          'invoice_id' => '',
          'is_pay_later' => '',
          'is_test' => '',
          'net_amount' => 2.34,
          'payment_instrument_id' => $payment_instrument_cc,
          'receipt_date' => '',
          'receive_date' => '2014-01-01 00:00:00',
          'source' => 'USD ' . 2.34,
          'thankyou_date' => '',
          'total_amount' => 2.34,
          'trxn_id' => "TEST_GATEWAY {$gateway_txn_id}",
          'financial_type_id' => \Civi\WMFHelper\ContributionRecur::getFinancialTypeForFirstContribution(),
        ],
      ],
    ];

    $gateway_txn_id = mt_rand();
    $cases['Country-only address'] = [
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
    $cases['Strip duff characters'] = [
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
    $cases['white_space_cleanup'] = [
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
    $cases['ampersands'] = [
      array_merge(
        $this->getMinimalImportData($gateway_txn_id),
        [
          // The multiple spaces & trailing ideographic space should go.
          // Internally I have set it to reduce multiple ideographic space to only one.
          // However, I've had second thoughts about my earlier update change to
          // convert them as they are formatted differently & the issue was not the
          // existence of them but the strings of several of them in a row.
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

    $cases['US address import is geocoded'] = [
      [
        'city' => 'Somerville',
        'country' => 'US',
        'currency' => 'USD',
        'date' => '2012-05-01 00:00:00',
        'email' => 'mouse@wikimedia.org',
        'gateway' => 'test_gateway',
        'gateway_txn_id' => $gateway_txn_id,
        'gross' => '1.23',
        'payment_method' => 'cc',
        'payment_submethod' => 'visa',
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

    $cases['opt in (yes)'] = [
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

    $cases['opt in (no)'] = [
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

    $cases['opt in (empty)'] = [
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

    $cases["'employer' field populated and mapped correctly"] = [
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
    $endowmentFinancialType = (string) \CRM_Core_PseudoConstant::getKey(
      'CRM_Contribute_BAO_Contribution', 'financial_type_id', 'Endowment Gift'
    );
    $cases['Endowment Gift, specified in utm_medium'] = [
      [
        'currency' => 'USD',
        'date' => '2018-07-01 00:00:00',
        'email' => 'mouse@wikimedia.org',
        'first_name' => 'First',
        'fee' => '0.03',
        'language' => 'en_US',
        'gateway' => 'test_gateway',
        'gateway_txn_id' => $gateway_txn_id,
        'gateway_status' => 'P',
        'gross' => '1.23',
        'last_name' => 'Mouse',
        'middle_name' => 'Middle',
        'payment_method' => 'cc',
        'payment_submethod' => 'visa',
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
          'currency' => 'USD',
          'fee_amount' => 0.03,
          'invoice_id' => '',
          'is_pay_later' => '',
          'is_test' => '',
          'net_amount' => 1.20,
          'payment_instrument_id' => $payment_instrument_cc,
          'receipt_date' => '',
          'receive_date' => '2018-07-01 00:00:00',
          'source' => 'USD 1.23',
          'total_amount' => '1.23',
          'trxn_id' => "TEST_GATEWAY {$gateway_txn_id}",
          'financial_type_id' => $endowmentFinancialType,
        ],
        'contribution_custom_values' => [
          'contribution_extra.gateway' => 'test_gateway',
          'contribution_extra.gateway_txn_id' => (string) $gateway_txn_id,
          'contribution_extra.gateway_status_raw' => 'P',
        ],
      ],
    ];

    $cases['Language es-419'] = [
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

    $cases['Unsupported 3 char language code'] = [
      array_merge(
        $this->getMinimalImportData($gateway_txn_id),
        [
          'language' => 'shn',
        ]
      ),
      [
        'contact' => [
          'preferred_language' => 'en_US',
        ],
        'contribution' => $this->getBaseContribution($gateway_txn_id),
      ],
    ];

    $cases['Unicode middle initial in full_name'] = [
      // Unicode middle initial in full_name is not mangled
      // for now, workaround sticks it on last name (which
      // may be the right thing to do for some cases)

      [
        'full_name' => 'Someone Ó Something',
        'country' => 'US',
        'currency' => 'USD',
        'date' => '2012-05-01 00:00:00',
        'email' => 'mouse@wikimedia.org',
        'gateway' => 'test_gateway',
        'gateway_txn_id' => $gateway_txn_id,
        'gross' => '1.23',
        'payment_method' => 'cc',
        'payment_submethod' => 'visa',
      ],
      [
        'contact' => [
          'first_name' => 'Someone',
          'last_name' => 'Ó Something',
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
      'payment_submethod' => 'visa',
      'contact_groups' => ['in_group'],
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
   * @throws CRM_Core_Exception
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

  public function testDuplicateHandling(): void {
    $invoiceID = mt_rand(0, 1000);
    $this->createContribution(['contact_id' => $this->createIndividual(), 'invoice_id' => $invoiceID]);
    $msg = [
      'currency' => 'USD',
      'date' => '2012-03-01 00:00:00',
      'gateway' => 'test_gateway',
      'order_id' => $invoiceID,
      'gross' => '1.23',
      'payment_method' => 'cc',
      'payment_submethod' => 'visa',
      'gateway_txn_id' => 'CON_TEST_GATEWAY' . mt_rand(),
    ];

    try {
      $this->messageImport($msg);
    }
    catch (WMFException $ex) {
      $this->assertTrue($ex->isRequeue());
      $this->assertEquals('DUPLICATE_INVOICE', $ex->getErrorName());
      $this->assertEquals(WMFException::DUPLICATE_INVOICE, $ex->getCode());
      return;
    }
    $this->fail('An exception was expected.');
  }

  /**
   * When we get a contact ID and matching hash and email, update instead of
   * creating new contact.
   *
   * @throws CRM_Core_Exception
   * @throws WMFException
   * @throws StatisticsCollectorException
   */
  public function testImportWithContactIdAndHash(): void {
    $existingContact = $this->createTestEntity('Contact', [
      'contact_type' => 'Individual',
      'first_name' => 'Test',
      'last_name' => 'Mouse',
    ], 'existing');
    $email = 'booboo' . mt_rand() . '@example.org';
    $this->callAPISuccess('Email', 'Create', [
      'contact_id' => $this->ids['Contact']['existing'],
      'email' => $email,
      'location_type_id' => 1,
    ]);
    $this->callAPISuccess('Address', 'Create', [
      'contact_id' => $this->ids['Contact']['existing'],
      'country' => wmf_civicrm_get_country_id('FR'),
      'street_address' => '777 Trompe L\'Oeil Boulevard',
      'location_type_id' => 1,
    ]);
    $expectedEmployer = "Subotnik's Apple Orchard";
    $msg = [
      'contact_id' => $this->ids['Contact']['existing'],
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
      'payment_submethod' => 'visa',
      'employer' => $expectedEmployer,
    ];
    $contribution = $this->processDonationMessage($msg);
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
   * @throws WMFException
   * @throws \CRM_Core_Exception
   */
  public function testImportWithContactIdAndBadHash() {
    $existingContact = $this->createTestEntity('Contact', [
      'contact_type' => 'Individual',
      'first_name' => 'Test',
      'last_name' => 'Mouse',
    ], 'existing');
    $email = 'booboo' . mt_rand() . '@example.org';
    $this->callAPISuccess('Email', 'Create', [
      'contact_id' => $this->ids['Contact']['existing'],
      'email' => $email,
      'location_type_id' => 1,
    ]);
    $this->callAPISuccess('Address', 'Create', [
      'contact_id' => $this->ids['Contact']['existing'],
      'country' => wmf_civicrm_get_country_id('FR'),
      'street_address' => '777 Trompe L\'Oeil Boulevard',
      'location_type_id' => 1,
    ]);
    $msg = [
      'contact_id' => $this->ids['Contact']['existing'],
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
      'payment_submethod' => 'visa',
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
   * @throws CRM_Core_Exception
   * @throws StatisticsCollectorException
   * @throws WMFException
   */
  public function testImportWithContactExisting(): void {
    $existingContact = $this->createTestEntity('Contact', [
      'contact_type' => 'Individual',
      'first_name' => 'Test',
      'last_name' => 'Mouse',
      'email_primary.email' => 'dupe@example.org',
    ]);

    $msg = [
      'first_name' => 'Test',
      'last_name' => 'Mouse',
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
      'payment_submethod' => 'visa',
    ];
    $contribution = wmf_civicrm_contribution_message_import($msg);
    $this->assertEquals($existingContact['id'], $contribution['contact_id']);
  }

  /**
   * If we get a matching contact name and email, update the preferred language
   *
   */
  public function testUpdateLanguageWithContactExisting() {
    $this->createTestEntity('Contact', [
      'contact_type' => 'Individual',
      'first_name' => 'Test',
      'last_name' => 'Mouse',
      'email_primary.email' => 'dupe@example.org',
      'preferred_language' => 'es_ES'
    ], 'existing');

    $msg = [
      'first_name' => 'Test',
      'last_name' => 'Mouse',
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
      'payment_submethod' => 'visa',
      // This should be normalized to es_MX and then used to update the contact record
      'language' => 'es-419'
    ];
    $this->processDonationMessage($msg);
    $this->assertContactValue($this->ids['Contact']['existing'], 'es_MX', 'preferred_language');
  }

  /**
   * If we get a matching contact email, add missing name fields from the message
   */
  public function testAddMissingNameWithContactExisting() {
    $existingContact = $this->createTestEntity('Contact', [
      'contact_type' => 'Individual',
      'email_primary.email' => 'noname@example.org',
      'preferred_language' => 'es_ES'
    ], 'existing');

    $msg = [
      'first_name' => 'NowIHave',
      'last_name' => 'AName',
      'currency' => 'USD',
      'date' => '2017-01-01 00:00:00',
      'invoice_id' => mt_rand(),
      'email' => 'noname@example.org',
      'country' => 'US',
      'street_address' => '123 42nd St. #321',
      'gateway' => 'test_gateway',
      'gateway_txn_id' => mt_rand(),
      'gross' => '1.25',
      'payment_method' => 'apple', // We skip name matching for Apple Pay donors
      'payment_submethod' => 'visa',
      // This should be normalized to es_MX and then used to update the contact record
      'language' => 'es-419'
    ];
    $this->processDonationMessage($msg);
    $contribution = $this->getContributionForMessage($msg);
    $this->assertEquals($existingContact['id'], $contribution['contact_id']);
    $this->assertContactValue($this->ids['Contact']['existing'], 'NowIHave', 'first_name');
    $this->assertContactValue($this->ids['Contact']['existing'], 'AName', 'last_name');
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
      'payment_submethod' => 'visa',
      'recurring' => 1,
      'recurring_payment_token' => mt_rand(),
      'user_ip' => '123.232.232'
    ];
    $contribution = $this->messageImport($msg);

  }

  public function testRecurringInitialSchemeTxnId() {
    $msg = [
      'first_name' => 'Lex',
      'currency' => 'USD',
      'date' => '2023-01-01 00:00:00',
      'invoice_id' => mt_rand(),
      'email' => 'totally.different@example.com',
      'country' => 'US',
      'street_address' => '123 42nd St. #321',
      'gateway' => 'Ingenico',
      'gateway_txn_id' => mt_rand(),
      'gross' => '1.25',
      'payment_method' => 'cc',
      'payment_submethod' => 'visa',
      'recurring' => 1,
      'recurring_payment_token' => mt_rand(),
      'initial_scheme_transaction_id' => 'FlargBlarg12345',
      'user_ip' => '123.232.232'
    ];
    $contribution = $this->messageImport($msg);
    $recurRecord = ContributionRecur::get(FALSE)
      ->addSelect('contribution_recur_smashpig.initial_scheme_transaction_id')
      ->addWhere('id', '=', $contribution['contribution_recur_id'])
      ->execute()
      ->first();
    $this->assertEquals(
      'FlargBlarg12345',
      $recurRecord['contribution_recur_smashpig.initial_scheme_transaction_id']
    );
  }

  /**
   * @dataProvider employerRelationDataProvider
   * @param string $sourceType
   * @param bool $isUpdate
   * @param ?bool $expected
   *
   * @throws CRM_Core_Exception
   */
  public function testIndicatesEmployerProvidedByDonor(string $sourceType, bool $isUpdate, ?bool $expected) {
    $orgContact = $this->createTestEntity('Contact', [
      'organization_name' => 'The Firm',
      'contact_type' => 'Organization',
    ], 'employer');

    $contactParams = [
      'first_name' => 'Philip',
      'last_name' => 'Mouse',
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
    $this->assertEquals('The Firm', $contactOrgName);
    // TODO: test with active relationship to other employer
  }

  /**
   * Data provider for employer metadata tests
   * @return array[]
   */
  public function employerRelationDataProvider(): array {
    return [
      'Should create new donor with employer, provided_by_donor = TRUE' => [
        'payments', FALSE, TRUE,
      ],
      'Should update donor with employer relationship, provided_by_donor = TRUE' => [
        'payments', TRUE, TRUE,
      ],
      'Should create new donor with employer, provided_by_donor not set' => [
        'direct', FALSE, NULL,
      ],
      'Should update donor with employer relationship, provided_by_donor not set' => [
        'direct', TRUE, NULL,
      ],
    ];
  }

  /**
   * @dataProvider messageProvider
   *
   * @todo - why do we run this with the messageProvider? Surely we just
   * need to run once with 1 dataset?
   *
   * @param array $msg
   * @throws CRM_Core_Exception
   */
  public function testMessageImportStatsCreatedOnImport(array $msg): void {
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
   */
  public function testMessageImportStatsProcessingRatesGenerated(array $msg, array $expected) {
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
   * array, but now we protect against this by disregarding any existing
   * start timestamps for timers that are started again.
   *
   * @see https://phabricator.wikimedia.org/T289175
   */
  public function testMessageImportStatsResetStartTimer(): void {
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
   * @param array $expected
   * @param array $actual
   */
  public function assertComparable(array $expected, array $actual) {
    $this->reformatMoneyFields($expected);
    $this->reformatMoneyFields($actual);
    foreach ($expected as $field => $value) {
      $this->assertEquals($value, $actual[$field], 'Expected match on field : ' . $field);
    }

  }

  protected function getMinimalImportData($gateway_txn_id): array {
    return [
      'currency' => 'USD',
      'date' => '2012-05-01 00:00:00',
      'email' => 'mouse@wikimedia.org',
      'gateway' => 'test_gateway',
      'gateway_txn_id' => $gateway_txn_id,
      'gross' => '1.23',
      'payment_method' => 'cc',
      'payment_submethod' => 'visa',
    ];
  }

  /**
   * Get the basic array of contribution data.
   *
   * @param string $gateway_txn_id
   *
   * @return array
   */
  protected function getBaseContribution(string $gateway_txn_id): array {
    $financial_type_cash = (int) \CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_Contribution', 'financial_type_id', 'Cash');
    $payment_instrument_cc = (int) \CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_Contribution', 'payment_instrument_id', 'Credit Card: Visa');
    return [
      'campaign_id' => '',
      'cancel_date' => '',
      'cancel_reason' => '',
      'check_number' => '',
      'contribution_page_id' => '',
      'contribution_recur_id' => '',
      'contribution_status_id' => '1',
      'currency' => 'USD',
      'fee_amount' => 0.00,
      'invoice_id' => '',
      'is_pay_later' => '',
      'is_test' => '',
      'net_amount' => '1.23',
      'payment_instrument_id' => $payment_instrument_cc,
      'receipt_date' => '',
      'receive_date' => '2012-05-01 00:00:00',
      'source' => 'USD 1.23',
      'thankyou_date' => '',
      'total_amount' => '1.23',
      'trxn_id' => "TEST_GATEWAY {$gateway_txn_id}",
      'financial_type_id' => $financial_type_cash,
    ];
  }

  /**
   * Remove commas from money fields.
   *
   * @param array $array
   */
  public function reformatMoneyFields(array &$array) {
    foreach ($array as $field => $value) {
      if (in_array($field, ['total_amount', 'source', 'net_amount', 'fee_amount'], TRUE)) {
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
  public function filterIgnoredFieldsFromArray(array $array): array {
    return array_diff_key($array, array_flip($this->fieldsToIgnore));
  }

}

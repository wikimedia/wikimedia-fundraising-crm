<?php

use Civi\Api4\ContributionRecur;
use Civi\Api4\Contribution;
use Civi\Api4\Email;
use Civi\Api4\Relationship;
use Civi\WMFException\WMFException;

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
   * Create a group and add to cleanup tracking.
   *
   * @param string $name
   *
   * @return int
   * @throws CRM_Core_Exception
   */
  protected function createGroup(string $name): int {
    $group = civicrm_api3('Group', 'get', ['title' => $name]);

    if ($group['count'] === 1) {
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
      $this->processMessageWithoutQueuing($msg, 'Donation', 'test');
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
   */
  public function testImportWithContactIdAndBadHash(): void {
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
    $this->processMessage($msg, 'Donation', 'test');
    $contribution = $this->getContributionForMessage($msg);
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
   * If we get a matching contact name and email, update the preferred language
   *
   */
  public function testUpdateLanguageWithContactExisting() {
    $this->createTestEntity('Contact', [
      'contact_type' => 'Individual',
      'first_name' => 'Test',
      'last_name' => 'Mouse',
      'email_primary.email' => 'dupe@example.org',
      'preferred_language' => 'es_ES',
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
      'language' => 'es-419',
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
      'preferred_language' => 'es_ES',
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
      'user_ip' => '123.232.232',
    ];
    $this->processMessage($msg, 'Donation', 'test');
    $contribution = $this->getContributionForMessage($msg);
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
   * @throws WMFException
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

    $this->processMessageWithoutQueuing($msg, 'Donation');
    $contribution = $this->getContributionForMessage($msg);
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

}

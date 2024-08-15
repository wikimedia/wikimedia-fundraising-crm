<?php

use Civi\Api4\ContributionRecur;
use Civi\Api4\Contribution;

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
      'cancel_date' => NULL,
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

  /**
   * When we get a contact ID and matching hash and email, update instead of
   * creating new contact.
   *
   * @throws CRM_Core_Exception
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
      'country' => 'France',
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
      'country' => 'France',
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

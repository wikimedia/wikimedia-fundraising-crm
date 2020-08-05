<?php

/**
 * @group Pipeline
 * @group WmfCivicrm
 * @group Merge
 */
class BlankAddressTest extends BaseWmfDrupalPhpUnitTestCase {

  /**
   * Id of the contact created in the setup function.
   *
   * @var int
   */
  protected $contactID;

  /**
   * Id of the contact created in the setup function.
   *
   * @var int
   */
  protected $contactID2;

  /**
   * @throws \Exception
   */
  public function setUp() {
    parent::setUp();
    civicrm_initialize();
    $this->doDuckHunt();
    // Run through the merge first to make sure there aren't pre-existing contacts in the DB
    // that will ruin the tests.
    $this->callAPISuccess('Job', 'process_batch_merge', ['mode' => 'safe']);

    $this->contactID = $this->breedDuck([wmf_civicrm_get_custom_field_name('do_not_solicit') => 0]);
    $this->contactID2 = $this->breedDuck([wmf_civicrm_get_custom_field_name('do_not_solicit') => 1]);
  }

  /**
   * @throws \CRM_Core_Exception
   */
  public function tearDown() {
    $this->callAPISuccess('Contribution', 'get', [
      'contact_id' => ['IN' => [$this->contactID, $this->contactID2]],
      'api.Contribution.delete' => 1,
    ]);
    $this->callAPISuccess('Contact', 'delete', ['id' => $this->contactID, 'skip_undelete' => TRUE]);
    $this->callAPISuccess('Contact', 'delete', ['id' => $this->contactID2, 'skip_undelete' => TRUE]);
    parent::tearDown();
  }

  /**
   * Data provider for merge hook to do both ways around.
   *
   * @return array
   */
  public function isReverse(): array {
    return [
      [FALSE],
      [TRUE],
    ];
  }

  /**
   * Clean up previous runs.
   *
   * Also get rid of the nest.
   */
  protected function doDuckHunt() {
    CRM_Core_DAO::executeQuery('
      DELETE c, e
      FROM civicrm_contact c
      LEFT JOIN civicrm_email e ON e.contact_id = c.id
      WHERE display_name = "Donald Duck" OR email = "the_don@duckland.com"');
    CRM_Core_DAO::executeQuery('DELETE FROM civicrm_prevnext_cache');
  }

  /**
   * Create contribution.
   *
   * @param array $params
   *   Array of parameters.
   *
   * @return int
   *   id of created contribution
   * @throws \CRM_Core_Exception
   */
  public function contributionCreate($params): int {

    $params = array_merge([
      'receive_date' => date('Ymd'),
      'total_amount' => 100.00,
      'fee_amount' => 5.00,
      'net_amount' => 95.00,
      'financial_type_id' => 1,
      'payment_instrument_id' => 1,
      'non_deductible_amount' => 10.00,
      'contribution_status_id' => 1,
    ], $params);

    $result = $this->callAPISuccess('contribution', 'create', $params);
    return (int) $result['id'];
  }

  /**
   * Create a test duck.
   *
   * @param array $extraParams
   *   Any overrides to be added to the create call.
   *
   * @return int
   * @throws \CRM_Core_Exception
   */
  public function breedDuck($extraParams = []): int {
    $contact = $this->callAPISuccess('Contact', 'create', array_merge([
      'contact_type' => 'Individual',
      'first_name' => 'Donald',
      'last_name' => 'Duck',
      'api.email.create' => [
        'email' => 'the_don@duckland.com',
        'location_type_id' => 'Work',
      ],
    ], $extraParams));
    return (int) $contact['id'];
  }

  /**
   * Test recovery where a blank email has overwritten a non-blank email on merge.
   *
   * In this case an email existed during merge that held no data. It was used
   * on the merge, but now we want the lost data.
   *
   * @throws \CRM_Core_Exception
   * @throws \CiviCRM_API3_Exception
   */
  public function testRepairBlankedAddressOnMerge() {
    $this->prepareForBlankAddressTests();
    $this->replicateBlankedAddress();

    $address = $this->callAPISuccessGetSingle('Address', ['contact_id' => $this->contactID]);
    $this->assertTrue(empty($address['street_address']));

    wmf_civicrm_fix_blanked_address($address['id']);
    $address = $this->callAPISuccessGetSingle('Address', ['contact_id' => $this->contactID]);
    $this->assertEquals('25 Mousey Way', $address['street_address']);

    $this->cleanupFromBlankAddressRepairTests();
  }

  /**
   * Test recovery where a blank email has overwritten a non-blank email on merge.
   *
   * In this case an email existed during merge that held no data. It was used
   * on the merge, but now we want the lost data. It underwent 2 merges.
   *
   * @throws \CRM_Core_Exception
   * @throws \CiviCRM_API3_Exception
   */
  public function testRepairBlankedAddressOnMergeDoubleWhammy() {
    $this->prepareForBlankAddressTests();
    $this->breedDuck(
      [
        'api.address.create' => [
          'street_address' => '25 Ducky Way',
          'country_id' => 'US',
          'contact_id' => $this->contactID,
          'location_type_id' => 'Main',
        ],
      ]);
    $this->replicateBlankedAddress();

    $address = $this->callAPISuccessGetSingle('Address', ['contact_id' => $this->contactID]);
    $this->assertTrue(empty($address['street_address']));

    wmf_civicrm_fix_blanked_address($address['id']);
    $address = $this->callAPISuccessGetSingle('Address', ['contact_id' => $this->contactID]);
    $this->assertEquals('25 Mousey Way', $address['street_address']);

    $this->cleanupFromBlankAddressRepairTests();
  }

  /**
   * Test recovery where an always-blank email has been transferred to another contact on merge.
   *
   * We have established the address was always blank and still exists. Lets
   * anihilate it.
   *
   * @throws \CRM_Core_Exception
   * @throws \CiviCRM_API3_Exception
   */
  public function testRemoveEternallyBlankMergedAddress() {
    $this->prepareForBlankAddressTests();

    $this->replicateBlankedAddress([
      'street_address' => NULL,
      'country_id' => NULL,
      'location_type_id' => 'Main',
    ]);

    $address = $this->callAPISuccessGetSingle('Address', ['contact_id' => $this->contactID]);
    $this->assertTrue(empty($address['street_address']));

    wmf_civicrm_fix_blanked_address($address['id']);
    $address = $this->callAPISuccess('Address', 'get', ['contact_id' => $this->contactID]);
    $this->assertEquals(0, $address['count']);

    $this->cleanupFromBlankAddressRepairTests();
  }

  /**
   * Test recovery where a secondary always-blank email has been transferred to another contact on merge.
   *
   * We have established the address was always blank and still exists, and there is
   * a valid other address. Lets annihilate it.
   *
   * @throws \CRM_Core_Exception
   * @throws \CiviCRM_API3_Exception
   */
  public function testRemoveEternallyBlankNonPrimaryMergedAddress() {
    $this->prepareForBlankAddressTests();
    $this->createContributions();

    $this->callAPISuccess('Address', 'create', [
      'street_address' => '25 Mousey Way',
      'country_id' => 'US',
      'contact_id' => $this->contactID,
      'location_type_id' => 'Main',
    ]);
    $this->callAPISuccess('Address', 'create', [
      'street_address' => 'something',
      'contact_id' => $this->contactID2,
      'location_type_id' => 'Main',
    ]);
    $this->callAPISuccess('Address', 'create', [
      'street_address' => '',
      'contact_id' => $this->contactID2,
      'location_type_id' => 'Main',
    ]);
    $this->callAPISuccess('Job', 'process_batch_merge', ['mode' => 'safe']);

    $address = $this->callAPISuccess('Address', 'get', ['contact_id' => $this->contactID, 'sequential' => 1]);
    $this->assertEquals(2, $address['count']);
    $this->assertTrue(empty($address['values'][1]['street_address']));

    wmf_civicrm_fix_blanked_address($address['values'][1]['id']);
    $address = $this->callAPISuccessGetSingle('Address', ['contact_id' => $this->contactID]);
    $this->assertEquals('something', $address['street_address']);

    $this->cleanupFromBlankAddressRepairTests();
  }

  /**
   * Replicate the merge that would result in a blanked address.
   *
   * @param array $overrides
   *
   * @throws \CRM_Core_Exception
   */
  protected function replicateBlankedAddress($overrides = []) {
    $this->createContributions();
    $this->callAPISuccess('Address', 'create', array_merge([
      'street_address' => '25 Mousey Way',
      'country_id' => 'US',
      'contact_id' => $this->contactID,
      'location_type_id' => 'Main',
    ], $overrides));
    $this->callAPISuccess('Address', 'create', [
      'street_address' => NULL,
      'contact_id' => $this->contactID2,
      'location_type_id' => 'Main',
    ]);
    $this->callAPISuccess('Job', 'process_batch_merge', ['mode' => 'safe']);
  }

  /**
   * Common code for testing blank address repairs.
   *
   * @throws \CiviCRM_API3_Exception
   */
  protected function prepareForBlankAddressTests() {
    civicrm_api3('Setting', 'create', [
      'logging_no_trigger_permission' => 0,
    ]);
    civicrm_api3('Setting', 'create', ['logging' => 1]);

    CRM_Core_DAO::executeQuery('DROP TABLE IF EXISTS blank_addresses');
    require_once __DIR__ . '/../../wmf_civicrm.install';
    require_once __DIR__ . '/../../update_restore_addresses.php';
    wmf_civicrm_update_7475();
  }

  /**
   * @throws \CiviCRM_API3_Exception
   */
  protected function cleanupFromBlankAddressRepairTests() {
    CRM_Core_DAO::executeQuery('DROP TABLE blank_addresses');

    civicrm_api3('Setting', 'create', [
      'logging_no_trigger_permission' => 1,
    ]);
  }

  /**
   * @throws \CRM_Core_Exception
   */
  protected function createContributions() {
    $this->contributionCreate([
      'contact_id' => $this->contactID,
      'receive_date' => '2010-01-01',
      'invoice_id' => 1,
      'trxn_id' => 1,
    ]);
    $this->contributionCreate([
      'contact_id' => $this->contactID2,
      'receive_date' => '2012-01-01',
      'invoice_id' => 2,
      'trxn_id' => 2,
    ]);
  }

  /**
   * Breed a donor duck.
   *
   * @param int $contactID
   * @param array $duckOverrides
   * @param bool $isLatestDonor
   *
   * @throws \CRM_Core_Exception
   */
  protected function breedGenerousDuck($contactID, $duckOverrides, $isLatestDonor) {
    $params = array_merge(['id' => $contactID], $duckOverrides);
    $this->breedDuck($params);
    $this->callAPISuccess('Contribution', 'create', [
      'contact_id' => $contactID,
      'financial_type_id' => 'Donation',
      'total_amount' => 5,
      'receive_date' => $isLatestDonor ? '2018-09-08' : '2015-12-20',
      'contribution_status_id' => 'Completed',
    ]);
  }

}

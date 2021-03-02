<?php

use CRM_Targetsmart_ExtensionUtil as E;
use Civi\Test\HeadlessInterface;
use Civi\Test\HookInterface;
use Civi\Test\TransactionalInterface;
use League\Csv\Reader;
use League\Csv\Writer;

/**
 * FIXME - Add test description.
 *
 * Tips:
 *  - With HookInterface, you may implement CiviCRM hooks directly in the test class.
 *    Simply create corresponding functions (e.g. "hook_civicrm_post(...)" or similar).
 *  - With TransactionalInterface, any data changes made by setUp() or test****() functions will
 *    rollback automatically -- as long as you don't manipulate schema or truncate tables.
 *    If this test needs to manipulate schema or truncate tables, then either:
 *       a. Do all that using setupHeadless() and Civi\Test.
 *       b. Disable TransactionalInterface, and handle all setup/teardown yourself.
 *
 * @group headless
 */
class CRM_TargetSmart_ImportTest extends \PHPUnit\Framework\TestCase implements HeadlessInterface, HookInterface, TransactionalInterface {

  use \Civi\Test\Api3TestTrait;

  /**
   * Ids of created objects.
   *
   * @var array
   */
  protected $ids = [];

  /**
   * @var string
   */
  protected $dataFolder;

  protected $importFile;

  /**
   * @var int
   */
  protected $minContactId;

  /**
   * Set up for running in headless mode.
   *
   * @return \Civi\Test\CiviEnvBuilder
   *
   * @throws \CRM_Extension_Exception_ParseException
   */
  public function setUpHeadless() {
    // Civi\Test has many helpers, like install(), uninstall(), sql(), and sqlFile().
    // See: https://docs.civicrm.org/dev/en/latest/testing/phpunit/#civitest
    return \Civi\Test::headless()
      ->installMe(__DIR__)
      ->apply();
  }

  /**
   * Instantiate CiviCRM.
   *
   * @throws \League\Csv\Exception
   */
  public function setUp() {
    parent::setUp();
    civicrm_initialize();
    $this->dataFolder = __DIR__ . '/../../data/';
    $this->importFile = $this->dataFolder . 'tsmartsample_test_ready.tsv';

    $this->prepareImportFile();
    // Sigh there is permissioning on loading custom
    global $user;
    $user->uid = 1;
  }

  public function tearDown() {
    parent::tearDown();
    $this->callAPISuccess('Contact', 'get', ['id' => ['>=' => $this->minContactId], 'api.contact.delete' => ['skip_undelete' => 1]]);
  }

  /**
   * Test importing our basic  contact.
   *
   * @throws \Exception
   */
  public function testBasicImport() {
    $this->callAPISuccess('TargetSmart', 'import', [
      'csv' => $this->importFile,
      'offset' => 1,
      'batch_limit' => 1,
    ]);
    $contactFields = $this->callAPISuccess('Contact', 'getfields', ['action' => 'get'])['values'];
    $imported = $this->callAPISuccess('Contact', 'get', ['id' => ['>=' => $this->minContactId], 'return' => array_keys($contactFields)])['values'];
    // Check offset worked & the first row was skipped.
    $this->assertEquals('original_value', $imported[$this->minContactId]['sort_name']);
    $updatedContact = $imported[$this->minContactId + 1];
    $this->assertEquals('original value, original value', $updatedContact['sort_name']);
    $this->assertEquals('original value', $updatedContact['nick_name']);
    $this->assertEquals('Male', $updatedContact['gender']);
    $this->assertEquals('1946-06-05', $updatedContact['birth_date']);
    $this->assertEquals('g', $this->getCustomValueForContact($updatedContact, 'Income_Range'));
    $this->assertEquals('J', $this->getCustomValueForContact($updatedContact, 'Estimated_Net_Worth'));
    $this->assertEquals(4, $this->getCustomValueForContact($updatedContact, 'Family_Composition'));
    $this->assertEquals(8, $this->getCustomValueForContact($updatedContact, 'Charitable_Contributions_Decile'));

    $this->assertEquals('my address', $updatedContact['street_address']);
    $this->assertEquals('', $updatedContact['supplemental_address_1']);
    $this->assertEquals('', $updatedContact['supplemental_address_2']);
    $this->assertEquals('Baltimore', $updatedContact['city']);
    $this->assertEquals(3925, $updatedContact['state_province_id']);
    $this->assertEquals('21212', $updatedContact['postal_code']);
    $this->assertEquals('1234', $updatedContact['postal_code_suffix']);

    $oldAddress = $this->callAPISuccessGetSingle('Address', ['contact_id' => $updatedContact['id'], 'location_type_id' => 'Old_2019', 'is_primary' => 0]);
    $this->assertEquals('52 Medium House', $oldAddress ['street_address']);
    $this->assertEquals('on the right', $oldAddress ['supplemental_address_1']);
    $this->assertEquals('', $oldAddress ['supplemental_address_2'] ?? '');
    $this->assertEquals('Another City', $oldAddress ['city']);
    $this->assertEquals(1042, $oldAddress ['state_province_id']);
    $this->assertEquals('T3X 2AS', $oldAddress ['postal_code']);
    $this->assertEquals('', $oldAddress ['postal_code_suffix'] ?? '');
    $this->assertEquals(1228, $oldAddress ['country_id']);

    $this->callAPISuccessGetCount('GroupContact', ['contact_id' => $updatedContact['id'], 'group_id' => 'TargetSmart2019'], 1);
  }

  /**
   * Test that we cope with an organization.
   *
   * @throws \Exception
   */
  public function testImportOrganization() {
    $this->callAPISuccess('Contact', 'create', ['id' => $this->minContactId, 'contact_type' => 'Organization']);
    $this->callAPISuccess('TargetSmart', 'import', [
      'csv' => $this->importFile,
      'offset' => 0,
      'batch_limit' => 1,
    ]);
    $contactFields = $this->callAPISuccess('Contact', 'getfields', ['action' => 'get'])['values'];
    $updatedContact = $this->callAPISuccessGetSingle('Contact', ['id' => $this->minContactId, 'return' => array_keys($contactFields)]);
    $this->assertEquals(90210, $updatedContact['postal_code']);
    $this->assertEquals(6, $this->getCustomValueForContact($updatedContact, 'Occupation'));
  }

  /**
   * Prepare import file.
   *
   * The import file holds the ids of existing contacts. Here we replace them with the ids of contacts
   * that exist in our test classes.
   *
   * @throws \League\Csv\CannotInsertRecord
   * @throws \League\Csv\Exception
   */
  protected function prepareImportFile() {
    $reader = Reader::createFromPath($this->dataFolder . 'tsmartsample.tsv')->setDelimiter("\t")->setHeaderOffset(0);
    $writer = Writer::createFromPath($this->importFile, 'w+')->setDelimiter("\t");

    $header = $reader->getHeader();
    $writer->insertOne($header);

    foreach ($reader->getRecords() as $offset => $record) {
      $contactType = $this->minContactId ? 'Individual' : 'Organization';
      $contactID = $this->callAPISuccess('Contact', 'create', ['first_name' => 'original value', 'last_name' => 'original value', 'nick_name' => 'original value', 'contact_type' => $contactType, 'organization_name' => 'original_value'])['id'];
      if (!$this->minContactId) {
        $this->minContactId = $contactID;
      }
      $this->callAPISuccess('Address', 'create', [
        'contact_id' => $contactID,
        'location_type_id' => 'Home',
        'street_address' => 'original_value',
        'supplemental_address_1' => 'original_value',
        'supplemental_address_2' => 'ov',
        'city' => 'ov',
        'postal_code' => 'ov',
        'postal_code_suffix' => 'ov'
      ]);
      $record['contact_id'] = $contactID;
      $writer->insertOne($record);
    }
  }

  /**
   * Get the custom field name.
   *
   * @return string
   *   eg. custom_6
   *
   * @throws \CiviCRM_API3_Exception
   */
  private function getCustomFieldName($name): string {
    return 'custom_' . (string) civicrm_api3('CustomField', 'getvalue', ['name' => $name, 'return' => 'id']);
  }

  /**
   * Get the relevant result.
   *
   * @param string
   *   Custom value.
   *
   * @return string
   */
  private function getCustomValueForContact($updatedContact, $name): string {
    return $updatedContact[$this->getCustomFieldName($name)];
  }

}

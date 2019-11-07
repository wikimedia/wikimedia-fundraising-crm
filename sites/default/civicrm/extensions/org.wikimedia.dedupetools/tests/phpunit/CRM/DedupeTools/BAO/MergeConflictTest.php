<?php

use CRM_Dedupetools_ExtensionUtil as E;
use Civi\Test\HeadlessInterface;
use Civi\Test\HookInterface;
use Civi\Test\TransactionalInterface;
use Civi\Test\Api3TestTrait;

require_once __DIR__  .'/../DedupeBaseTestClass.php';
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
class CRM_DedupeTools_BAO_MergeConflictTest extends DedupeBaseTestClass {

  use Api3TestTrait;

  /**
   * Setup for class.
   */
  public function setUp() {
    parent::setUp();
    $this->callAPISuccess('Setting', 'create', [
      'deduper_resolver_bool_prefer_yes' => ['on_hold', 'do_not_email', 'do_not_phone', 'do_not_mail', 'do_not_sms', 'do_not_trade', 'is_opt_out'],
    ]);
  }

  /**
   * Test the boolean resolver works.
   *
   * @throws \CiviCRM_API3_Exception
   */
  public function testGetBooleanFields() {
    $fields = CRM_Dedupetools_BAO_MergeConflict::getBooleanFields();
    $this->assertTrue(isset($fields['do_not_mail'], $fields['on_hold']));
    $this->assertFalse(isset($fields['contact_type']));
    $this->assertFalse(isset($fields['is_deleted']));
  }

  /**
   * Test that a boolean field is resolved if set.
   */
  public function testResolveBooleanFields() {
    $this->createDuplicateIndividuals([['do_not_mail' => 0], ['do_not_mail' => 1]]);
    $this->callAPISuccess('Contact', 'merge', ['to_keep_id' => $this->ids['contact'][0], 'to_remove_id' => $this->ids['contact'][1]]);
    $mergedContacts = $this->callAPISuccess('Contact', 'get', ['id' => ['IN' => $this->ids['contact']]])['values'];

    $this->assertEquals(1, $mergedContacts[$this->ids['contact'][1]]['contact_is_deleted']);
    $this->assertEquals(0, $mergedContacts[$this->ids['contact'][0]]['contact_is_deleted']);
    $this->assertEquals(1, $mergedContacts[$this->ids['contact'][0]]['do_not_mail']);

    // Now try merging a contact with 0 in that field into our retained contact.
    $this->ids['contact'][2] = $this->callAPISuccess('Contact', 'create', ['first_name' => 'bob', 'do_not_mail' => 0, 'contact_type' => 'Individual'])['id'];
    $this->callAPISuccess('Contact', 'merge', ['to_keep_id' => $this->ids['contact'][0], 'to_remove_id' => $this->ids['contact'][2]]);
    $mergedContacts = $this->callAPISuccess('Contact', 'get', ['id' => ['IN' => $this->ids['contact'], 'is_deleted' => 0]])['values'];

    $this->assertEquals(1, $mergedContacts[$this->ids['contact'][0]]['do_not_mail']);

    $this->assertEquals(1, $mergedContacts[$this->ids['contact'][2]]['contact_is_deleted']);
    $this->assertEquals(0, $mergedContacts[$this->ids['contact'][0]]['contact_is_deleted']);
  }

  /**
   * Test the boolean field resolver resolves emails on hold.
   *
   * @param bool $isReverse
   *   Should we reverse which contact has on_hold set to true.
   *
   * @dataProvider booleanDataProvider
   *
   * @throws \CRM_Core_Exception
   */
  public function testResolveEmailOnHold($isReverse) {
    $this->createDuplicateIndividuals();
    // Conveniently our 2 contacts are 0 & 1 in the $this->ids['contact'] array so we can abuse the boolean var like this.
    $contactIDOnHold = $isReverse;

    $email1 = $this->callAPISuccess('Email', 'get', ['contact_id' => $this->ids['contact'][$contactIDOnHold]])['id'];
    $this->callAPISuccess('Email', 'create', ['id' => $email1, 'on_hold' => 1]);

    $mergeResult = $this->callAPISuccess('Contact', 'merge', ['to_keep_id' => $this->ids['contact'][0], 'to_remove_id' => $this->ids['contact'][1]])['values'];
    $this->assertCount(1, $mergeResult['merged']);
    $email0 = $this->callAPISuccessGetSingle('Email', ['contact_id' => ['IN' => [$this->ids['contact'][0]]]]);
    $this->assertEquals(1, $email0['on_hold']);
  }

  /**
   * Data provider for tests with 2 options
   *
   * @return array
   */
  public function booleanDataProvider() {
    return [[0], [1]];
  }

  /**
   * Test resolving an initial in the first name.
   *
   * @param bool $isReverse
   *   Should we reverse which contact we merge into.
   *
   * @dataProvider booleanDataProvider
   *
   * @throws \CRM_Core_Exception
   */
  public function testInitialResolution($isReverse) {
    $this->createDuplicateIndividuals([['first_name' => 'Bob M'], []]);
    $mergedContact = $this->doMerge($isReverse);
    $this->assertEquals('Bob', $mergedContact['first_name']);
    $this->assertEquals('M', $mergedContact['middle_name']);
  }

  /**
   * Test resolving an initial in the first name.
   *
   * @param bool $isReverse
   *   Should we reverse which contact we merge into.
   *
   * @dataProvider booleanDataProvider
   *
   * @throws \CRM_Core_Exception
   */
  public function testInitialResolutionInLast($isReverse) {
    $this->createDuplicateIndividuals([['last_name' => 'M Smith'], []]);
    $mergedContact = $this->doMerge($isReverse);
    $this->assertEquals('Bob', $mergedContact['first_name']);
    $this->assertEquals('M', $mergedContact['middle_name']);
    $this->assertEquals('Smith', $mergedContact['last_name']);
  }

  /**
   * Test resolving an initial in the first name.
   *
   * @param bool $isReverse
   *   Should we reverse which contact we merge into.
   *
   * @dataProvider booleanDataProvider
   *
   * @throws \CRM_Core_Exception
   */
  public function testInitialResolutionNameIsInitial($isReverse) {
    $this->createDuplicateIndividuals([['last_name' => 'S', 'first_name' => 'B'], []]);
    $mergedContact = $this->doMerge($isReverse);
    $this->assertEquals('Bob', $mergedContact['first_name']);
    $this->assertEquals('Smith', $mergedContact['last_name']);
  }

  /**
   * Test resolving an initial in the first name when the other contact already has the same value as an initial
   *
   * @param bool $isReverse
   *   Should we reverse which contact we merge into.
   *
   * @dataProvider booleanDataProvider
   *
   * @throws \CRM_Core_Exception
   */
  public function testInitialResolutionNameInitialExists($isReverse) {
    $this->createDuplicateIndividuals([['first_name' => 'Bob J'], ['middle_name' => 'J']]);
    $mergedContact = $this->doMerge($isReverse);
    $this->assertEquals('Bob', $mergedContact['first_name']);
    $this->assertEquals('Smith', $mergedContact['last_name']);
    $this->assertEquals('J', $mergedContact['middle_name']);
  }

  /**
   * Test resolving an initial in the first name when the other contact already has the same value as an initial with a dot.
   *
   * ie. [first_name => 'Bob J'] vs ['first_name' => 'Bob', 'middle_name' => 'J.']
   *
   * @param bool $isReverse
   *   Should we reverse which contact we merge into.
   *
   * @dataProvider booleanDataProvider
   *
   * @throws \CRM_Core_Exception
   */
  public function testInitialResolutionNameInitialExistsDotted($isReverse) {
    $this->createDuplicateIndividuals([['first_name' => 'Bob J.'], ['middle_name' => 'J.']]);
    $mergedContact = $this->doMerge($isReverse);
    $this->assertEquals('Bob', $mergedContact['first_name']);
    $this->assertEquals('Smith', $mergedContact['last_name']);
    $this->assertEquals('J', $mergedContact['middle_name']);
  }

  /**
   *  Test resolving a situation where the first name is duplicated in the full name.
   *
   * e.g
   * ['first_name' => 'Bob', 'last_name' => 'Bob Max Smith'],
   * ['first_name' => 'Bob', 'last_name' => 'Max Smith']
   *
   * @param bool $isReverse
   *   Should we reverse which contact we merge into.
   *
   * @dataProvider booleanDataProvider
   *
   * @throws \CRM_Core_Exception
   */
  public function testMisplacedNameResolutionFullNameInFirstName($isReverse) {
    $this->createDuplicateIndividuals([['last_name' => 'null', 'first_name' => 'Bob M Smith'], []]);
    $mergedContact = $this->doMerge($isReverse);
    $this->assertEquals('Bob', $mergedContact['first_name']);
    $this->assertEquals('Smith', $mergedContact['last_name']);
    $this->assertEquals('M', $mergedContact['middle_name']);
  }

  /**
   * Test resolving an initial in the first name.
   *
   * @param bool $isReverse
   *   Should we reverse which contact we merge into.
   *
   * @dataProvider booleanDataProvider
   *
   * @throws \CRM_Core_Exception
   */
  public function testMisplacedNameResolutionFullNameInLastName($isReverse) {
    $this->createDuplicateIndividuals([['first_name' => 'null', 'last_name' => 'Bob M Smith'], []]);
    $mergedContact = $this->doMerge($isReverse);
    $this->assertEquals('Bob', $mergedContact['first_name']);
    $this->assertEquals('Smith', $mergedContact['last_name']);
    $this->assertEquals('M', $mergedContact['middle_name']);
  }

  /**
   * Test resolving an initial in the first name.
   *
   * @param bool $isReverse
   *   Should we reverse which contact we merge into.
   *
   * @dataProvider booleanDataProvider
   *
   * @throws \CRM_Core_Exception
   */
  public function testMisplacedNameResolutionFullRepeatedInLastName($isReverse) {
    $this->createDuplicateIndividuals([['first_name' => 'Bob', 'last_name' => 'Bob Max Smith'], ['last_name' => 'Max Smith']]);
    $mergedContact = $this->doMerge($isReverse);
    $this->assertEquals('Bob', $mergedContact['first_name']);
    $this->assertEquals('Max Smith', $mergedContact['last_name']);
  }

  /**
   * Test resolving an initial in the first name with punctuation.
   *
   * @param bool $isReverse
   *   Should we reverse which contact we merge into.
   *
   * @dataProvider booleanDataProvider
   *
   * @throws \CRM_Core_Exception
   */
  public function testMisplacedNameResolutionWithPunctuation($isReverse) {
    $this->createDuplicateIndividuals([['first_name' => 'null', 'last_name' => 'Bob M. Smith'], []]);
    $mergedContact = $this->doMerge($isReverse);
    $this->assertEquals('Bob', $mergedContact['first_name']);
    $this->assertEquals('Smith', $mergedContact['last_name']);
    $this->assertEquals('M', $mergedContact['middle_name']);
  }

  /**
   * Test that a name field that is the same apart from white space can be resolved.
   *
   * @param bool $isReverse
   *   Should we reverse which contact we merge into.
   *
   * @dataProvider booleanDataProvider
   *
   * @throws \CRM_Core_Exception
   */
  public function testResolveWhiteSpaceInName($isReverse) {
    $this->createDuplicateIndividuals([['first_name' => 'alter ego'], ['first_name' => 'alterego']]);
    $mergedContact = $this->doMerge($isReverse);
    $this->assertEquals('alter ego', $mergedContact['first_name']);
  }

  /**
   * Create individuals to dedupe.
   *
   * @param array $contactParams
   *   Arrays of parameters, one per contact.
   */
  private function createDuplicateIndividuals($contactParams = [[], []]) {
    $params = [
      'first_name' => 'Bob',
      'last_name' => 'Smith',
      'contact_type' => 'Individual',
      'email' => 'bob@example.com',
    ];
    foreach ($contactParams as $index => $contactParam) {
      $contactParam = array_merge($params, $contactParam);
      $this->ids['contact'][$index] = $this->callAPISuccess('Contact', 'create', $contactParam)['id'];
    }
  }

  /**
   * @param $isReverse
   *
   * @return array|int
   * @throws \CRM_Core_Exception
   */
  protected function doMerge($isReverse) {
    $toKeepContactID = $isReverse ? $this->ids['contact'][1] : $this->ids['contact'][0];
    $toDeleteContactID = $isReverse ? $this->ids['contact'][0] : $this->ids['contact'][1];
    $mergeResult = $this->callAPISuccess('Contact', 'merge', ['to_keep_id' => $toKeepContactID, 'to_remove_id' => $toDeleteContactID])['values'];
    $this->assertCount(1, $mergeResult['merged']);
    $mergedContact = $this->callAPISuccessGetSingle('Contact', ['id' => $toKeepContactID]);
    return $mergedContact;
  }

}

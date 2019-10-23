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
    $this->callAPISuccess('Contact', 'merge', ['to_keep_id' => $this->ids['Contact'][0], 'to_remove_id' => $this->ids['Contact'][1]]);
    $mergedContacts = $this->callAPISuccess('Contact', 'get', ['id' => ['IN' => $this->ids['Contact']]])['values'];

    $this->assertEquals(1, $mergedContacts[$this->ids['Contact'][1]]['contact_is_deleted']);
    $this->assertEquals(0, $mergedContacts[$this->ids['Contact'][0]]['contact_is_deleted']);
    $this->assertEquals(1, $mergedContacts[$this->ids['Contact'][0]]['do_not_mail']);

    // Now try merging a contact with 0 in that field into our retained contact.
    $this->ids['Contact'][2] = $this->callAPISuccess('Contact', 'create', ['first_name' => 'bob', 'do_not_mail' => 0, 'contact_type' => 'Individual'])['id'];
    $this->callAPISuccess('Contact', 'merge', ['to_keep_id' => $this->ids['Contact'][0], 'to_remove_id' => $this->ids['Contact'][2]]);
    $mergedContacts = $this->callAPISuccess('Contact', 'get', ['id' => ['IN' => $this->ids['Contact'], 'is_deleted' => 0]])['values'];

    $this->assertEquals(1, $mergedContacts[$this->ids['Contact'][0]]['do_not_mail']);

    $this->assertEquals(1, $mergedContacts[$this->ids['Contact'][2]]['contact_is_deleted']);
    $this->assertEquals(0, $mergedContacts[$this->ids['Contact'][0]]['contact_is_deleted']);
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
    // Conveniently our 2 contacts are 0 & 1 in the $this->ids['Contact'] array so we can abuse the boolean var like this.
    $contactIDOnHold = $isReverse;

    $email1 = $this->callAPISuccess('Email', 'get', ['contact_id' => $this->ids['Contact'][$contactIDOnHold]])['id'];
    $this->callAPISuccess('Email', 'create', ['id' => $email1, 'on_hold' => 1]);

    $mergeResult = $this->callAPISuccess('Contact', 'merge', ['to_keep_id' => $this->ids['Contact'][0], 'to_remove_id' => $this->ids['Contact'][1]])['values'];
    $this->assertCount(1, $mergeResult['merged']);
    $email0 = $this->callAPISuccessGetSingle('Email', ['contact_id' => ['IN' => [$this->ids['Contact'][0]]]]);
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
   * Create individuals to dedupe.
   *
   * @param array $contactParams
   *   Arrays of parameters.
   */
  private function createDuplicateIndividuals($contactParams = [[], []]) {
    $params = [
      'first_name' => 'bob',
      'last_name' => 'Smith',
      'contact_type' => 'Individual',
      'email' => 'bob@example.com',
    ];
    foreach ($contactParams as $index => $contactParam) {
      $contactParam = array_merge($params, $contactParam);
      $this->ids['Contact'][$index] = $this->callAPISuccess('Contact', 'create', $contactParam)['id'];
    }
  }

}

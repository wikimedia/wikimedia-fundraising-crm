<?php

use CRM_Datachecks_ExtensionUtil as E;
use Civi\Test\HeadlessInterface;
use Civi\Test\HookInterface;
use Civi\Test\TransactionalInterface;

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
class BlankLocationTest extends \PHPUnit\Framework\TestCase implements HeadlessInterface, HookInterface, TransactionalInterface {
  use \Civi\Test\Api3TestTrait;

  protected $addressParams = [
    'street_address' => '123 ABC st', 'city' => 'LeaningVille', 'location_type_id' => 'Home'
  ];

  /**
   * Set up for headless tests.
   *
   * @return \Civi\Test\CiviEnvBuilder
   * @throws \CRM_Extension_Exception_ParseException
   */
  public function setUpHeadless() {
    // Civi\Test has many helpers, like install(), uninstall(), sql(), and sqlFile().
    // See: https://docs.civicrm.org/dev/en/latest/testing/phpunit/#civitest
    return \Civi\Test::headless()
      ->installMe(__DIR__)
      ->apply();
  }

  public function tearDown(): void {
    parent::tearDown();
    $this->callAPISuccess('Data', 'fix', ['check' => 'BlankLocation']);
  }

  /**
   * Test that an address with a blank location is resolved through allocating a location type..
   */
  public function testCheckAndFixBlankLocationAddress() {
    $contact = $this->callAPISuccess('Contact', 'create', ['contact_type' => 'Individual', 'first_name' => 'Adam', 'last_name' => 'Ant']);
    $this->addressParams['contact_id'] = $contact['id'];
    $this->callAPISuccess('Address', 'create', $this->addressParams);
    CRM_Core_DAO::executeQuery('UPDATE civicrm_address SET location_type_id = NULL WHERE contact_id = ' . $contact['id']);

    $check = $this->callAPISuccess('Data', 'check', ['check' => 'BlankLocation']);
    $this->assertEquals(['contact' => [$contact['id']]], $check['values']['BlankLocation']['address']['example']);

    $this->callAPISuccess('Data', 'fix', ['check' => 'BlankLocation']);

    $check = $this->callAPISuccess('Data', 'check', ['check' => 'BlankLocation']);
    $this->assertTrue(empty($check['values']['BlankLocation']['address']));

    $address = $this->callAPISuccess('Address', 'getsingle', ['contact_id' => $contact['id']]);
    $this->assertTrue(!empty($address['location_type_id']));
  }

}

<?php

use Civi\Api4\Contact;
use Civi\Test\Api3TestTrait;
use Civi\Test\EntityTrait;
use CRM_Datachecks_ExtensionUtil as E;
use Civi\Test\HeadlessInterface;
use Civi\Test\HookInterface;
use Civi\Test\TransactionalInterface;
use PHPUnit\Framework\TestCase;

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
class PrimaryLocationTest extends TestCase implements HeadlessInterface, HookInterface {
  use Api3TestTrait;
  use EntityTrait;

  protected array $addressParams = [
    'street_address' => '123 ABC st',
    'city' => 'LeaningVille',
    'location_type_id' => 'Home',
  ];

  public function setUpHeadless() {
    // Civi\Test has many helpers, like install(), uninstall(), sql(), and sqlFile().
    // See: https://docs.civicrm.org/dev/en/latest/testing/phpunit/#civitest
    return \Civi\Test::headless()
      ->installMe(__DIR__)
      ->apply();
  }

  public function tearDown(): void {
    Contact::delete(FALSE)
      ->addWhere('id', 'IN', $this->ids['Contact'])
      ->setUseTrash(FALSE)
      ->execute();
    parent::tearDown();
  }

  /**
   * Test that lack of primary addresses is resolved where only one exists.
   */
  public function testCheckAndFixNoPrimaryOneAddress() {
    $contact = $this->createTestEntity('Contact', ['contact_type' => 'Individual', 'first_name' => 'Adam', 'last_name' => 'Ant']);
    $this->addressParams['contact_id'] = $contact['id'];
    $this->createTestEntity('Address', $this->addressParams);
    CRM_Core_DAO::executeQuery('UPDATE civicrm_address SET is_primary = 0 WHERE contact_id = ' . $contact['id']);

    $check = $this->callAPISuccess('Data', 'check', ['check' => 'PrimaryLocation']);
    $this->assertEquals(['contact' => [$contact['id']]], $check['values']['PrimaryLocation']['address']['example']);

    $this->callAPISuccess('Data', 'fix', ['check' => 'PrimaryLocation']);
    $check = $this->callAPISuccess('Data', 'check', ['check' => 'PrimaryLocation']);
    $this->assertTrue(empty($check['values']['PrimaryLocation']['address']));

    $address = $this->callAPISuccess('Address', 'getsingle', ['contact_id' => $contact['id']]);
    $this->assertEquals(1, $address['is_primary']);
  }

  /**
   * Test that lack of primary addresses is resolved where more than one exists.
   */
  public function testCheckAndFixNoPrimaryAddresses() {
    $contact = $this->createTestEntity('Contact', ['contact_type' => 'Individual', 'first_name' => 'Adam', 'last_name' => 'Ant']);
    $this->addressParams['contact_id'] = $contact['id'];
    $this->createTestEntity('Address', $this->addressParams);
    $this->addressParams['location_type_id'] = 'Other';
    $this->createTestEntity('Address', $this->addressParams);
    CRM_Core_DAO::executeQuery('UPDATE civicrm_address SET is_primary = 0 WHERE contact_id = ' . $contact['id']);

    $check = $this->callAPISuccess('Data', 'check', ['check' => 'PrimaryLocation']);
    $this->assertEquals(['contact' => [$contact['id']]], $check['values']['PrimaryLocation']['address']['example']);

    $this->callAPISuccess('Data', 'fix', ['check' => 'PrimaryLocation']);
    $check = $this->callAPISuccess('Data', 'check', ['check' => 'PrimaryLocation']);
    $this->assertTrue(empty($check['values']['PrimaryLocation']['address']));
    $this->callAPISuccessGetSingle('Address', ['contact_id' => $contact['id'], 'is_primary' => 1]);
  }

}

<?php

use CRM_Thethe_ExtensionUtil as E;
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
class TheTheTest extends \PHPUnit\Framework\TestCase implements HeadlessInterface, HookInterface, TransactionalInterface {

  use Civi\Test\Api3TestTrait;

  /**
   * Set up for headless tests.
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
   * Test sort name is saved with changes - we use
   * 1) the default 'The ' for prefix
   * 2) a suffix strings a single string
   * 3) an array for anywhere strings
   */
  public function testSaveSortName() {
    Civi::settings()->set('thethe_org_suffix_strings', 'Ltd');
    Civi::settings()->set('thethe_org_anywhere_strings', ['%', '-']);
    $this->callAPISuccess('Contact', 'create', [
      'organization_name' => 'The Top 10 -% Ltd',
      'contact_type' => 'Organization',
    ]);
    $organization = $this->callAPISuccess('Contact', 'getsingle', ['organization_name' => 'The Top 10 -% Ltd']);
    $this->assertEquals('Top 10', $organization['sort_name']);
  }

}

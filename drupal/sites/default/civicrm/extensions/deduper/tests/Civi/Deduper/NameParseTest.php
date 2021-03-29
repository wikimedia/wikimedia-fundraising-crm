<?php


namespace Civi\Deduper;

use Civi\Api4\Name;
use Civi\Test;
use Civi\Test\CiviEnvBuilder;
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
class NameParseTest extends TestCase implements HeadlessInterface, HookInterface, TransactionalInterface {

  /**
   * Setup used when HeadlessInterface is implemented.
   *
   * Civi\Test has many helpers, like install(), uninstall(), sql(), and sqlFile().
   *
   * @see See: https://github.com/civicrm/org.civicrm.testapalooza/blob/master/civi-test.md
   *
   * @return \Civi\Test\CiviEnvBuilder
   *
   * @throws \CRM_Extension_Exception_ParseException
   */
  public function setUpHeadless(): CiviEnvBuilder {
    return Test::headless()
      ->installMe(__DIR__)
      ->apply();
  }

  /**
   * Get names to parse.
   *
   * @return \string[][]
   */
 public function getNameVariants(): array {
   return [
     ['name' => 'Mr. Paul Fudge']
   ];
 }

  /**
   * Test name passing.
   *
   * @dataProvider getNameVariants
   *
   * @param string $name
   *
   * @throws \API_Exception
   */
 public function testNameParsing(string $name): void {
    $result = Name::parse()->setNames([$name])->execute()->first();
    $this->assertEquals('Mr.', $result['prefix_id:label']);
    $this->assertEquals('Paul', $result['first_name']);
    $this->assertEquals('Fudge', $result['last_name']);
 }

}

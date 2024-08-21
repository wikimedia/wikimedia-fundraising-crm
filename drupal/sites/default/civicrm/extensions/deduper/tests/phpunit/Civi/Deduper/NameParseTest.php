<?php


namespace Civi\Deduper;

use Civi\Api4\Contact;
use Civi\Api4\Name;
use Civi\Api4\System;
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
 *  - With HookInterface, you may implement CiviCRM hooks directly in the test
 * class. Simply create corresponding functions (e.g. "hook_civicrm_post(...)"
 * or similar).
 *  - With TransactionalInterface, any data changes made by setUp() or
 * test****() functions will rollback automatically -- as long as you don't
 * manipulate schema or truncate tables. If this test needs to manipulate
 * schema or truncate tables, then either: a. Do all that using setupHeadless()
 * and Civi\Test. b. Disable TransactionalInterface, and handle all
 * setup/teardown yourself.
 *
 * @group headless
 */
class NameParseTest extends TestCase implements HeadlessInterface, HookInterface, TransactionalInterface {

  use Test\EntityTrait;

  /**
   * Setup used when HeadlessInterface is implemented.
   *
   * Civi\Test has many helpers, like install(), uninstall(), sql(), and
   * sqlFile().
   *
   * @see https://github.com/civicrm/org.civicrm.testapalooza/blob/master/civi-test.md
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
     [
       'name' => 'Mr. Paul Fudge',
       'expected' => [
         'prefix_id:label' => 'Mr.',
         'first_name' => 'Paul',
         'last_name' => 'Fudge',
       ],
     ],
     [
       'name' => 'Mr. Andrew and Mrs Sally Smith',
       'expected' => [
         'first_name' => 'Andrew',
         'last_name' => 'Smith',
         'Partner.Partner' => 'Mrs Sally Smith',
       ],
     ],
    ];
  }

  /**
   * Test name passing.
   *
   * @dataProvider getNameVariants
   *
   * @param string $name
   * @param array $expected
   *
   * @throws \CRM_Core_Exception
   */
  public function testNameParsing(string $name, array $expected): void {
    $result = Name::parse()->setNames([$name])->execute()->first();
    foreach ($expected as $key => $value) {
      $this->assertEquals($value, $result[$key]);
    }
  }

  /**
   * Test the the full_name field added to Contact.create by this extension works.
   *
   * @throws \CRM_Core_Exception
   */
  public function testNameParseOnCreate(): void {
    $fields = Contact::getFields(FALSE)
      ->addWhere('usage', 'CONTAINS', 'import')
      ->setAction('save')
      ->execute()->indexBy('name');
    $this->assertArrayHasKey('full_name', $fields);
    $individual = $this->createTestEntity('Contact', [
      'contact_type' => 'Individual',
      'full_name' => 'Bob M. Smith',
    ]);
    $this->assertEquals('Bob', $individual['first_name']);
    $this->assertEquals('M.', $individual['middle_name']);
    $this->assertEquals('Smith', $individual['last_name']);

    Contact::update(FALSE)
      ->addWhere('id', '=', $individual['id'])
      ->setValues(['full_name' => 'Robert Mathew Smith'])
      ->execute();

    $contact = Contact::get(FALSE)
      ->addWhere('id', '=', $individual['id'])
      ->execute()->single();
    $this->assertEquals('Robert', $contact['first_name']);

    Contact::save(FALSE)
      ->setRecords([['id' => $individual['id']]])
      ->setDefaults(['full_name' => 'Bobby Smith'])
      ->execute();

    $contact = Contact::get(FALSE)
      ->addSelect('first_name', 'addressee_id:name')
      ->addWhere('id', '=', $individual['id'])
      ->execute()->single();
    $this->assertEquals('Bobby', $contact['first_name']);
    $this->assertEquals('Customized', $contact['addressee_id:name']);

    Contact::save(FALSE)
      ->setRecords([['id' => $individual['id'], 'full_name' => 'Bobby Smith']])
      ->execute();

    $contact = Contact::get(FALSE)
      ->addSelect('first_name', 'addressee_id:name')
      ->addWhere('id', '=', $individual['id'])
      ->execute()->single();
    $this->assertEquals('Bobby', $contact['first_name']);
    $this->assertEquals('Customized', $contact['addressee_id:name']);
  }

}

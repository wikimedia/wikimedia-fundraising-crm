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
   * @return string[][]
   */
  public function getNameVariants(): array {
    return [
      // -----------------------------------------------------------------------
      // Original tests.
      // -----------------------------------------------------------------------

      'Mr. Paul Fudge' => [
        'name' => 'Mr. Paul Fudge',
        'expected' => [
          'prefix_id:label' => 'Mr.',
          'first_name' => 'Paul',
          'last_name' => 'Fudge',
        ],
      ],

      'Mr. Andrew and Mrs Sally Smith' => [
        'name' => 'Mr. Andrew and Mrs Sally Smith',
        'expected' => [
          'first_name' => 'Andrew',
          'last_name' => 'Smith',
          'Partner.Partner' => 'Mrs Sally Smith',
        ],
      ],

      /** - these 2 do not work right now
       We are patch-welcome on them upstream... https://github.com/iliaal/nameparser/issues/4
      'Mr. Andrew Jones and Mrs Sally Smith' => [
        'name' => 'Mr. Andrew Jones and Mrs Sally Smith',
        'expected' => [
          'first_name' => 'Andrew',
          'last_name' => 'Jones',
          'Partner.Partner' => 'Mrs Sally Smith',
        ],
      ],
      'Andrew Jones and Sally Smith' => [
        'name' => 'Andrew Jones and Sally Smith',
        'expected' => [
          'first_name' => 'Andrew',
          'last_name' => 'Jones',
          'Partner.Partner' => 'Mrs Sally Smith',
        ],
      ],
       */

      'Andrew and Sally Smith' => [
        'name' => 'Andrew and Sally Smith',
        'expected' => [
          'first_name' => 'Andrew',
          'last_name' => 'Smith',
          'Partner.Partner' => 'Sally Smith',
        ],
      ],

      'Irish surname with space name' => [
        // https://en.wikipedia.org/wiki/%C3%89amon_%C3%93_Cu%C3%ADv
        'name' => 'Éamon Ó Cuív',
        'expected' => [
          'first_name' => 'Éamon',
          'middle_name' => '',
          'last_name' => 'Ó Cuív',
        ],
      ],

      'Māori initial with middle name' => [
        'name' => 'John Ā Rātana',
        'expected' => [
          'first_name' => 'John',
          'middle_name' => 'Ā',
          'last_name' => 'Rātana',
        ],
      ],

      'Mr. and Mrs. Brad Smith' => [
        'name' => 'Mr. and Mrs. Brad Smith',
        'expected' => [
          'prefix_id:label' => 'Mr.',
          'first_name' => 'Brad',
          'last_name' => 'Smith',
          'Partner.Partner' => 'Mrs. Smith',
        ],
      ],
      'Mr. & Mrs. Brad Smith' => [
        'name' => 'Mr. & Mrs. Brad Smith',
        'expected' => [
          'prefix_id:label' => 'Mr.',
          'first_name' => 'Brad',
          'last_name' => 'Smith',
          'Partner.Partner' => 'Mrs. Smith',
        ],
      ],

      // -----------------------------------------------------------------------
      // Additional salutations from patch.
      // -----------------------------------------------------------------------

      'Patch: Dame' => [
        'name' => 'Dame Susan Devoy',
        'expected' => [
          'prefix_id:label' => 'Dame',
          'first_name' => 'Susan',
          'last_name' => 'Devoy',
        ],
      ],

      'Patch: Hon' => [
        'name' => 'Hon. Jane Smith',
        'expected' => [
          'prefix_id:label' => 'Hon.',
          'first_name' => 'Jane',
          'last_name' => 'Smith',
        ],
      ],

      'Patch: Honorable alias' => [
        'name' => 'Honorable Jane Smith',
        'expected' => [
          'prefix_id:label' => 'Hon.',
          'first_name' => 'Jane',
          'last_name' => 'Smith',
        ],
      ],

      'Patch: The Honorable alias' => [
        'name' => 'The Honorable Jane Smith',
        'expected' => [
          'prefix_id:label' => 'Hon.',
          'first_name' => 'Jane',
          'last_name' => 'Smith',
        ],
      ],

      'Patch: Lady' => [
        'name' => 'Lady Barbara Judge',
        'expected' => [
          'prefix_id:label' => 'Lady',
          'first_name' => 'Barbara',
          'last_name' => 'Judge',
        ],
      ],

      'Patch: Lord' => [
        'name' => 'Lord Michael Ashcroft',
        'expected' => [
          'prefix_id:label' => 'Lord',
          'first_name' => 'Michael',
          'last_name' => 'Ashcroft',
        ],
      ],

      'Patch: Missus alias' => [
        'name' => 'Missus Sally Smith',
        'expected' => [
          'prefix_id:label' => 'Mrs.',
          'first_name' => 'Sally',
          'last_name' => 'Smith',
        ],
      ],

      'Patch: Pastor' => [
        'name' => 'Pastor John Smith',
        'expected' => [
          'prefix_id:label' => 'Pastor',
          'first_name' => 'John',
          'last_name' => 'Smith',
        ],
      ],

      'Patch: Reverend alias' => [
        'name' => 'Reverend John Smith',
        'expected' => [
          'prefix_id:label' => 'Rev.',
          'first_name' => 'John',
          'last_name' => 'Smith',
        ],
      ],

      'Patch: Rt Hon' => [
        'name' => 'Rt Hon Winston Peters',
        'expected' => [
          'prefix_id:label' => 'Rt Hon.',
          'first_name' => 'Winston',
          'last_name' => 'Peters',
        ],
      ],

      'Patch: Professor alias' => [
        'name' => 'Professor Jane Smith',
        'expected' => [
          'prefix_id:label' => 'Prof.',
          'first_name' => 'Jane',
          'last_name' => 'Smith',
        ],
      ],

      'Patch: His Honour' => [
        'name' => 'His Honour John Walker',
        'expected' => [
          'prefix_id:label' => 'His Honour',
          'first_name' => 'John',
          'last_name' => 'Walker',
        ],
      ],

      'Patch: Her Honour' => [
        'name' => 'Her Honour Anne Hinton',
        'expected' => [
          'prefix_id:label' => 'Her Honour',
          'first_name' => 'Anne',
          'last_name' => 'Hinton',
        ],
      ],

      // -----------------------------------------------------------------------
      // Additional prefix / credential additions from patch.
      // -----------------------------------------------------------------------

      'Patch: DDS' => [
        'name' => 'Jane Smith DDS',
        'expected' => [
          'first_name' => 'Jane',
          'last_name' => 'Smith',
          'suffix_id:label' => 'DDS',
        ],
      ],

      'Patch: DO' => [
        'name' => 'John Smith DO',
        'expected' => [
          'first_name' => 'John',
          'last_name' => 'Smith',
          'suffix_id:label' => 'DO',
        ],
      ],

      'Patch: DMD' => [
        'name' => 'Jane Smith DMD',
        'expected' => [
          'first_name' => 'Jane',
          'last_name' => 'Smith',
          'suffix_id:label' => 'DMD',
        ],
      ],

      'Patch: DVM' => [
        'name' => 'John Smith DVM',
        'expected' => [
          'first_name' => 'John',
          'last_name' => 'Smith',
          'suffix_id:label' => 'DVM',
        ],
      ],

      'Patch: EMBA' => [
        'name' => 'Jane Smith EMBA',
        'expected' => [
          'first_name' => 'Jane',
          'last_name' => 'Smith',
          'suffix_id:label' => 'EMBA',
        ],
      ],

      'Patch: Esquire alias' => [
        'name' => 'John Smith Esquire',
        'expected' => [
          'first_name' => 'John',
          'last_name' => 'Smith',
          'suffix_id:label' => 'Esquire',
        ],
      ],

      'Patch: LCSW' => [
        'name' => 'Jane Smith LCSW',
        'expected' => [
          'first_name' => 'Jane',
          'last_name' => 'Smith',
          'suffix_id:label' => 'LCSW',
        ],
      ],

      'Patch: MBA' => [
        'name' => 'John Smith MBA',
        'expected' => [
          'first_name' => 'John',
          'last_name' => 'Smith',
          'suffix_id:label' => 'MBA',
        ],
      ],

      'Patch: MS' => [
        'name' => 'Jane Smith MS',
        'expected' => [
          'first_name' => 'Jane',
          'last_name' => 'Smith',
          'suffix_id:label' => 'MS',
        ],
      ],

      'Patch: MSW' => [
        'name' => 'John Smith MSW',
        'expected' => [
          'first_name' => 'John',
          'last_name' => 'Smith',
          'suffix_id:label' => 'MSW',
        ],
      ],

      'Patch: PsyD' => [
        'name' => 'Jane Smith PsyD',
        'expected' => [
          'first_name' => 'Jane',
          'last_name' => 'Smith',
          'suffix_id:label' => 'PsyD',
        ],
      ],

      'Patch: RPh' => [
        'name' => 'John Smith RPh',
        'expected' => [
          'first_name' => 'John',
          'last_name' => 'Smith',
          'suffix_id:label' => 'RPh',
        ],
      ],

      'Patch: DSW' => [
        'name' => 'Jane Smith DSW',
        'expected' => [
          'first_name' => 'Jane',
          'last_name' => 'Smith',
          'suffix_id:label' => 'DSW',
        ],
      ],

      // -----------------------------------------------------------------------
      // Numeral suffixes.
      // -----------------------------------------------------------------------

      'Patch: VI suffix' => [
        'name' => 'John Smith VI',
        'expected' => [
          'first_name' => 'John',
          'last_name' => 'Smith',
          'suffix_id:label' => 'VI',
        ],
      ],

      'Patch: 10th suffix' => [
        'name' => 'John Smith 10th',
        'expected' => [
          'first_name' => 'John',
          'last_name' => 'Smith',
          'suffix_id:label' => '10th',
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
      $this->assertEquals($value, $result[$key], json_encode($result));
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

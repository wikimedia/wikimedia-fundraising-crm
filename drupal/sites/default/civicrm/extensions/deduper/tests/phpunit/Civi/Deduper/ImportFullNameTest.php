<?php


namespace Civi\Deduper;

use Civi\Api4\Contact;
use Civi\Api4\Contribution;
use Civi\Api4\ContributionSoft;
use Civi\Api4\Import;
use Civi\Api4\Name;
use Civi\Api4\System;
use Civi\Api4\UserJob;
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
class ImportFullNameTest extends TestCase implements HeadlessInterface, HookInterface {

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
      ->install('civiimport')
      ->apply();
  }

  /**
   * @throws \CRM_Core_Exception
   */
  public function tearDown(): void {
    \CRM_Core_DAO::executeQuery('DROP TABLE IF EXISTS civicrm_tmp_d_abc');
    Contribution::delete(FALSE)
      ->addWhere('trxn_id', '=', 'E-I-E-I-O')
      ->execute();
    Contact::delete(FALSE)
      ->setUseTrash(FALSE)
      ->addWhere('last_name', '=', 'Import_Test')
      ->execute();
    UserJob::delete(FALSE)
      ->addWhere('id', 'IN', $this->ids['UserJob'])
      ->execute();
    parent::tearDown();
  }

  /**
   * @throws \CRM_Core_Exception
   * @throws \CRM_Core_Exception
   * @throws \Civi\API\Exception\UnauthorizedException
   */
  public function testNameParseDuringImport(): void {
    $this->imitateAdminUser();
    $this->createImportSource();
    $importMappings = [
      [
        'name' => 'full_name',
        'default_value' => NULL,
        'column_number' => 0,
        'entity_data' => [],
      ],
      [
        'name' => 'total_amount',
        'default_value' => 10,
        'column_number' => 1,
        'entity_data' => [],
      ],
      [
        'name' => 'financial_type_id',
        'default_value' => 1,
        'column_number' => 2,
        'entity_data' => [],
      ],
      [
        'name' => 'soft_credit.contact.full_name',
        'default_value' => NULL,
        'column_number' => 3,
        'entity_data' => ['soft_credit' => ['soft_credit_type_id' => 1]],
      ],
      [
        'name' => 'trxn_id',
        'default_value' => '',
        'column_number' => 4,
        'entity_data' => [],
      ],
    ];
    $userJobID = $this->createTestEntity('UserJob', [
      'job_type' => 'contribution_import',
      'status_id' => 1,
      'metadata' => [
        'submitted_values' => [
          'contactType' => 'Individual',
          'dateFormats' => 1,
          'dataSource' => 'CRM_Import_DataSource_SQL',
          'onDuplicate' => 1,
        ],
        'Template' => ['mapping_id' => NULL],
        'DataSource' => [
          'table_name' => 'civicrm_tmp_d_abc',
          'column_headers' => ['full_name', 'total_amount', 'financial_type_id', 'other_person', 'trxn_id'],
          'number_of_columns' => 5,
        ],
        'sqlQuery' => "SELECT full_name, total_amount, financial_type_id, other_person, trxn_id FROM civicrm_tmp_d_abc",
        'entity_configuration' => [
          'Contribution' => ['action' => 'save'],
          'Contact' => [
            'action' => 'save',
            'contact_type' => 'Individual',
            'dedupe_rule' => 'IndividualUnsupervised',
          ],
          'SoftCreditContact' => [
            'contact_type' => 'Individual',
            'soft_credit_type_id' => 1,
            'action' => 'save',
            'entity' => ['entity_data' => ['soft_credit_type_id' => 1]],
          ],
        ],
        'import_mappings' => $importMappings,
      ],
    ])['id'];
    Import::import($userJobID)->execute();
    $row = Import::get($userJobID, FALSE)
      ->execute()->first();
    $this->assertEquals('soft_credit_imported', $row['_status'], $row['_status_message']);
    $contribution = Contribution::get(FALSE)
      ->addSelect('contact_id.first_name', 'contact_id.last_name')
      ->addWhere('id', '=', $row['_entity_id'])
      ->execute()->single();
    $this->assertEquals('Jane', $contribution['contact_id.first_name']);
    $this->assertEquals('Import_Test', $contribution['contact_id.last_name']);
    $softCredit = ContributionSoft::get(FALSE)
      ->addSelect('contact_id.first_name', 'contact_id.last_name')
      ->addWhere('contribution_id', '=', $contribution['id'])
      ->execute()->single();
    // It does also do some capitalization tweaks.
    $this->assertEquals('Bob', $softCredit['contact_id.first_name']);
    $this->assertEquals('Import_Test', $contribution['contact_id.last_name']);
  }

  /**
   * @return void
   */
  public function imitateAdminUser(): void {
    \CRM_Core_Config::singleton()->userPermissionClass = new \CRM_Core_Permission_UnitTests();
    \CRM_Core_Config::singleton()->userPermissionClass->permissions = [
      'edit all contacts',
      'access CiviCRM',
      'add contacts',
      'administer CiviCRM',
      'edit contributions',
      'access CiviContribute',
      'administer queues',
      'view all contacts',
    ];
  }

  /**
   *
   * @noinspection PhpUnhandledExceptionInspection
   * @noinspection UnknownInspectionInspection
   */
  public function createImportSource(): void {
    \CRM_Core_DAO::executeQuery("CREATE TABLE civicrm_tmp_d_abc (
  `full_name` VARCHAR(128),
  `total_amount` VARCHAR(128) DEFAULT '',
  `financial_type_id` VARCHAR(128) DEFAULT '',
  `other_person` VARCHAR(128),
  `trxn_id` VARCHAR(128),
  `_entity_id` INT(11) DEFAULT NULL,
  `_status` VARCHAR(32) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'NEW',
  `_status_message` LONGTEXT COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `_id` INT(11) NOT NULL AUTO_INCREMENT,
  PRIMARY KEY (`_id`),
  KEY `_id` (`_id`),
  KEY `_status` (`_status`)
) ");
    \CRM_Core_DAO::executeQuery('INSERT INTO civicrm_tmp_d_abc (full_name, other_person, trxn_id) SELECT "Jane Import_Test" as full_name, "BOB Import_Test" as other_person, "E-I-E-I-O" as trxn_id');
  }

}

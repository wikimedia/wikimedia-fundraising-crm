<?php

namespace Civi\Wmf;

use Civi\Api4\Contact;
use Civi\Api4\Contribution;
use Civi\Api4\Import;
use Civi\Api4\Managed;
use Civi\Api4\UserJob;
use Civi\Test;
use Civi\Test\CiviEnvBuilder;
use Civi\Test\HeadlessInterface;
use Civi\Test\HookInterface;
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
class ImportTest extends TestCase implements HeadlessInterface, HookInterface {

  use Test\Api3TestTrait;

  /**
   * @return \Civi\Test\CiviEnvBuilder
   * @throws \CRM_Extension_Exception_ParseException
   */
  public function setUpHeadless(): CiviEnvBuilder {
    // Civi\Test has many helpers, like install(), uninstall(), sql(), and sqlFile().
    // See: https://docs.civicrm.org/dev/en/latest/testing/phpunit/#civitest
    return Test::headless()
      ->installMe(__DIR__)
      ->apply();
  }

  /**
   * Clean up after test.
   *
   * @throws \Civi\Core\Exception\DBQueryException
   * @throws \CRM_Core_Exception
   */
  protected function tearDown(): void {
    UserJob::delete(FALSE)->addWhere('metadata', 'LIKE', '%civicrm_tmp_d_abc%')->execute();
    \CRM_Core_DAO::executeQuery('DROP TABLE IF EXISTS civicrm_tmp_d_abc');
    Contribution::delete(FALSE)->addWhere('contact_id.nick_name', '=', 'Trading Name')->execute();
    Contact::delete(FALSE)->addWhere('nick_name', '=', 'Trading Name')->setUseTrash(FALSE)->execute();
    parent::tearDown();
  }

  /**
   * @throws \Civi\API\Exception\UnauthorizedException
   * @throws \CRM_Core_Exception
   */
  public function testImportOrganizationName(): void {
    $this->imitateAdminUser();
    $this->createImportTable();
    $organizationID = Contact::create(FALSE)->setValues(['contact_type' => 'Organization', 'organization_name' => 'Long legal name', 'nick_name' => 'Trading Name'])->execute()->first()['id'];
    $jobID = UserJob::create(FALSE)->setValues([
      'job_type' => 'contribution_import',
      'status_id' => 1,
      'metadata' => [
        'submitted_values' => [
          'contactType' => 'Organization',
          'dateFormats' => 1,
          'dataSource' => 'CRM_Import_DataSource_SQL',
          'onDuplicate' => 1,
        ],
        'Template' => ['mapping_id' => NULL],
        'DataSource' => [
            'table_name' => 'civicrm_tmp_d_abc',
            'column_headers' => [
              'financial_type_id',
              'total_amount',
              'organization_name',
            ],
            'number_of_columns' => 3,
          ],
        'sqlQuery' => $this->getSelectQuery(),
        'entity_configuration' => [
          'Contribution' => ['action' => 'create'],
          'Contact' => [
            'action' => 'select',
            'contact_type' => 'Organization',
            'dedupe_rule' => 'OrganizationUnsupervised',
          ],
          'SoftCreditContact' => [
            'contact_type' => '',
            'soft_credit_type_id' => 1,
            'action' => 'ignore',
          ],
        ],
        'import_mappings' => [
           [
              'name' => 'financial_type_id',
              'default_value' => NULL,
              'column_number' => 0,
              'entity_data' => [],
            ],
           [
            'name' => 'total_amount',
            'default_value' => NULL,
            'column_number' => 1,
            'entity_data' => [],
          ],
           [
              'name' => 'organization_name',
              'default_value' => NULL,
              'column_number' => 2,
              'entity_data' => [],
            ],
        ],
      ],
    ])->execute()->first()['id'];
    Import::import($jobID, FALSE)->execute();
    $contributions = Contribution::get()->addWhere('contact_id', '=', $organizationID)->addSelect(
      'contribution_extra.gateway',
      'source'
    )->execute();
    $this->assertCount(1, $contributions);
    $contribution = $contributions->first();
    $this->assertEquals('USD 50.00', $contribution['source']);
    $this->assertEquals('Matching Gifts', $contribution['contribution_extra.gateway']);
  }

  private function getSelectQuery(): string {
    return "SELECT 'Engage' AS financial_type_id, 50 AS total_amount, 'Trading Name' AS organization_name FROM civicrm_contact LIMIT 1";
  }
  /**
   * Create the table that would be created on submitting the first (DataSource) form.
   *
   * @throws \Civi\Core\Exception\DBQueryException
   */
  protected function createImportTable(): void {
    \CRM_Core_DAO::executeQuery("CREATE TABLE civicrm_tmp_d_abc (
  `financial_type_id` VARCHAR(6) CHARACTER SET utf8mb4 NOT NULL,
  `total_amount` INT(2) NOT NULL,
  `organization_name` VARCHAR(12) CHARACTER SET utf8mb4 NOT NULL,
  `_entity_id` INT(11) DEFAULT NULL,
  `_status` VARCHAR(32) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'NEW',
  `_status_message` LONGTEXT COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `_id` INT(11) NOT NULL AUTO_INCREMENT,
  PRIMARY KEY (`_id`),
  KEY `_id` (`_id`),
  KEY `_status` (`_status`)
) ");
    \CRM_Core_DAO::executeQuery('INSERT INTO civicrm_tmp_d_abc (financial_type_id, total_amount, organization_name) ' . $this->getSelectQuery());
  }


  /**
   * Emulate a logged in user since certain functions use that.
   * value to store a record in the DB (like activity)
   * CRM-8180
   *
   * @return int
   *   Contact ID of the created user.
   */
  public function imitateAdminUser(): int {
    $result = $this->callAPISuccess('UFMatch', 'get', [
      'uf_id' => 1,
      'sequential' => 1,
    ]);
    if (empty($result['id'])) {
      $contact = $this->callAPISuccess('Contact', 'create', [
        'first_name' => 'Super',
        'last_name' => 'Duper',
        'contact_type' => 'Individual',
        'api.UFMatch.create' => ['uf_id' => 1, 'uf_name' => 'Wizard'],
      ]);
      $contactID = $contact['id'];
    }
    else {
      $contactID = $result['values'][0]['contact_id'];
    }
    \CRM_Core_Session::singleton()->set('userID', $contactID);
    \CRM_Core_Config::singleton()->userPermissionClass = new \CRM_Core_Permission_UnitTests();
    \CRM_Core_Config::singleton()->userPermissionClass->permissions = [
      'edit all contacts',
      'access CiviCRM',
      'administer CiviCRM',
      'edit contributions',
      'access CiviContribute',
      'administer queues',
      'view all contacts',
    ];
    return $contactID;
  }

}

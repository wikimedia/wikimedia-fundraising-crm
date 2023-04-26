<?php

namespace Civi\Wmf;

use Civi\Api4\Contact;
use Civi\Api4\Contribution;
use Civi\Api4\ContributionSoft;
use Civi\Api4\Import;
use Civi\Api4\Relationship;
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

  protected $ids = [];

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
    \Civi::cache('metadata')->delete('civiimport_table_fieldscivicrm_tmp_d_abc');
    Contribution::delete(FALSE)->addWhere('contact_id.nick_name', '=', 'Trading Name')->execute();
    Contribution::delete(FALSE)->addWhere('contact_id.organization_name', '=', 'Trading Name')->execute();
    Contribution::delete(FALSE)->addWhere('contact_id.last_name', '=', 'Doe')->execute();

    Contact::delete(FALSE)->addWhere('nick_name', '=', 'Trading Name')->setUseTrash(FALSE)->execute();
    Contact::delete(FALSE)->addWhere('organization_name', '=', 'Trading Name')->setUseTrash(FALSE)->execute();

    Contact::delete(FALSE)
      ->addWhere('first_name', '=', 'Jane')
      ->addWhere('last_name', '=', 'Doe')
      ->setUseTrash(FALSE)->execute();
    parent::tearDown();
    unset(\Civi::$statics['wmf_contact']);
  }

  /**
   * Test importing an organization with an individual being soft credited.
   *
   * We are looking to see that
   *
   * 1) the organization can be found based on a nick_name look up
   * 2) the contact can be found based on first name & last name match + relationship
   * 3) the contribution source is populated.
   * @throws \CRM_Core_Exception
   */
  public function testImportOrganizationWithRelated(): void {
    $this->imitateAdminUser();
    $data = [
      'financial_type_id' => 'Engage',
      'total_amount' => 50,
      'organization_name' => 'Trading Name',
      'first_name' => 'Jane',
      'last_name' => 'Doe',
      'email' => 'jane@example.com',
    ];
    $this->createImportTable($data);

    $this->ids['Organization'] = Contact::create()->setValues(['contact_type' => 'Organization', 'organization_name' => 'Long legal name', 'nick_name' => 'Trading Name'])->execute()->first()['id'];
    // Create 2 'Jane Doe' contacts - we test it finds the second one, who has an employer relationship.
    Contact::create()->setValues(['contact_type' => 'Individual', 'first_name' => 'Jane', 'last_name' => 'Doe'])->execute()->first()['id'];
    $this->ids['Individual'] = Contact::create()->setValues(['contact_type' => 'Individual', 'first_name' => 'Jane', 'last_name' => 'Doe', 'employer_id' => $this->ids['Organization']])->execute()->first()['id'];

    $this->runImport($data);
    $contributions = Contribution::get()->addWhere('contact_id', '=', $this->ids['Organization'])->addSelect(
      'contribution_extra.gateway',
      'source'
    )->execute();
    $this->assertCount(1, $contributions);
    $contribution = $contributions->first();
    $this->assertEquals('USD 50.00', $contribution['source']);
    $this->assertEquals('Matching Gifts', $contribution['contribution_extra.gateway']);

    $softCredits = ContributionSoft::get()->addWhere('contribution_id', '=', $contribution['id'])->execute();
    $this->assertCount(1, $softCredits);
    $softCredit = $softCredits->first();
    $this->assertEquals($this->ids['Individual'], $softCredit['contact_id']);
  }

  /**
   * Test importing an organization with the soft credit individual being a previous soft creditor.
   *
   * We are looking to see that
   *
   * 1) the organization can be found based on an organization_name look up
   * 2) the contact can be found based on first name & last name match + soft credit
   * 3) the relationship is created.
   * @throws \CRM_Core_Exception
   */
  public function testImportOrganizationWithSoftCredit(): void {
    $this->imitateAdminUser();
    $data = [
      'financial_type_id' => 'Engage',
      'total_amount' => 50,
      'organization_name' => 'Trading Name',
      'first_name' => 'Jane',
      'last_name' => 'Doe',
      'email' => 'jane@example.com',
    ];
    $this->createImportTable($data);

    $contributionID = $this->createSoftCreditConnectedContacts();

    $this->runImport($data);
    $contribution = Contribution::get()->addWhere('contact_id', '=', $this->ids['Organization'])
      ->addWhere('id', '>', $contributionID)
      ->addSelect(
      'contribution_extra.gateway',
      'source'
    )->execute()->first();

    $softCredits = ContributionSoft::get()->addWhere('contribution_id', '=', $contribution['id'])->execute();
    $this->assertCount(1, $softCredits);
    $softCredit = $softCredits->first();
    $this->assertEquals($this->ids['Individual'], $softCredit['contact_id']);
    // Creating the soft credit should have created a relationship.
    $relationship = Relationship::get()->addWhere('contact_id_a', '=', $this->ids['Individual'])->execute()->first();
    $this->assertEquals($this->ids['Organization'], $relationship['contact_id_b']);

  }

  /**
   * Test importing an organization doing an id based lookup.
   *
   *
   * @throws \CRM_Core_Exception
   */
  public function testImportOrganizationUsingID(): void {
    $this->imitateAdminUser();
    $this->createOrganization();
    $data = [
      'financial_type_id' => 'Engage',
      'total_amount' => 50,
      'contribution_contact_id' => $this->ids['Organization'],
      'first_name' => 'Jane',
      'last_name' => 'Doe',
      'email' => 'jane@example.com',
    ];
    $this->createImportTable($data);
    $this->runImport($data);
    $contributions = Contribution::get()->addWhere('contact_id', '=', $this->ids['Organization'])->execute();
    $this->assertCount(1, $contributions);
  }

  /**
   * Test importing an individual with the soft credit organization being a previous soft creditor.
   *
   * We are looking to see that
   *
   * 1) the organization can be found based on an organization_name look up
   * 2) the contact can be found based on first name & last name match + soft credit
   * 3) the relationship is created.
   * @throws \CRM_Core_Exception
   */
  public function testImportIndividualWithSoftCredit(): void {
    $this->imitateAdminUser();
    $data = [
      'financial_type_id' => 'Engage',
      'total_amount' => 50,
      'organization_name' => 'Trading Name',
      'first_name' => 'Jane',
      'last_name' => 'Doe',
      'email' => 'jane@example.com',
    ];
    $this->createImportTable($data);

    $contributionID = $this->createSoftCreditConnectedContacts();

    $this->runImport($data, 'Individual');
    // The contacts have 2 contributions with soft credits - use greater than filter
    // to exclude the one that already existed.
    $contribution = Contribution::get()->addWhere('contact_id', '=', $this->ids['Individual'])
      ->addWhere('id', '>', $contributionID)
      ->addSelect(
        'contribution_extra.gateway',
        'source'
      )->execute()->first();

    $softCredits = ContributionSoft::get()->addWhere('contribution_id', '=', $contribution['id'])->execute();
    $this->assertCount(1, $softCredits);
    $softCredit = $softCredits->first();
    $this->assertEquals($this->ids['Organization'], $softCredit['contact_id']);
    // Creating the soft credit should have created a relationship.
    $relationship = Relationship::get()->addWhere('contact_id_a', '=', $this->ids['Individual'])->execute()->first();
    $this->assertEquals($this->ids['Organization'], $relationship['contact_id_b']);

  }

  private function getSelectQuery($columns): string {
    $columnSQL = [];
    foreach ($columns as $column => $data) {
      $columnSQL[] = "'$data' as $column";
    }
    return "SELECT " . implode(',', $columnSQL) . " FROM civicrm_contact LIMIT 1";
  }
  /**
   * Create the table that would be created on submitting the first (DataSource) form.
   *
   * @throws \Civi\Core\Exception\DBQueryException
   * @noinspection SqlResolve
   */
  protected function createImportTable($columns = []): void {
    $fieldSql = [];
    foreach (array_keys($columns) as $column) {
      $fieldSql[] = "`$column` VARCHAR(255) CHARACTER SET utf8mb4 NOT NULL";
    }
    \CRM_Core_DAO::executeQuery('CREATE TABLE civicrm_tmp_d_abc (
  ' . implode(',', $fieldSql) . ",
  `_entity_id` INT(11) DEFAULT NULL,
  `_status` VARCHAR(32) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'NEW',
  `_status_message` LONGTEXT COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `_id` INT(11) NOT NULL AUTO_INCREMENT,
  PRIMARY KEY (`_id`),
  KEY `_id` (`_id`),
  KEY `_status` (`_status`)
) ");
    \CRM_Core_DAO::executeQuery('INSERT INTO civicrm_tmp_d_abc (' . implode(',', array_keys($columns)) . ') ' . $this->getSelectQuery($columns));
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
      'add contacts',
      'administer CiviCRM',
      'edit contributions',
      'access CiviContribute',
      'administer queues',
      'view all contacts',
    ];
    return $contactID;
  }

  /**
   * Get the soft credit type ID that would trigger relationship creation.
   *
   * @return int
   * @throws \CRM_Core_Exception
   */
  private function getEmploymentSoftCreditType(): int {
    $types = ContributionSoft::getFields(FALSE)
      ->setLoadOptions(['id', 'name'])
      ->addWhere('name', '=', 'soft_credit_type_id')
      ->execute()
      ->first()['options'];
    foreach ($types as $type) {
      if ($type['name'] === 'workplace') {
        return $type['id'];
      }
    }
    throw new \CRM_Core_Exception('workplace soft credit type does not exist');
  }

  /**
   * Helper to run the import.
   *
   * @param array $data
   * @param string $mainContactType
   *
   * @throws \CRM_Core_Exception
   */
  private function runImport(array $data, string $mainContactType = 'Organization'): void {
    $softCreditTypeID = $this->getEmploymentSoftCreditType();
    $importMappings = [];
    foreach (array_keys($data) as $index => $columnName) {
      switch ($columnName) {
        case 'organization_name':
          $importMappings[] = [
            'name' => $mainContactType === 'Organization' ? 'organization_name' : 'soft_credit.contact.organization_name',
            'default_value' => NULL,
            'column_number' => $index,
            'entity_data' => $mainContactType === 'Organization' ? [] : ['soft_credit' => ['soft_credit_type_id' => $softCreditTypeID]],
          ];
          break;

        case 'first_name':
        case 'last_name':
          $importMappings[] = [
            'name' => $mainContactType === 'Organization' ? 'soft_credit.contact.' . $columnName : $columnName,
            'default_value' => NULL,
            'column_number' => $index,
            'entity_data' => $mainContactType === 'Organization' ? ['soft_credit' => ['soft_credit_type_id' => $softCreditTypeID]] : [],
          ];
          break;

        default:
          $importMappings[] = [
            'name' => $columnName,
            'default_value' => NULL,
            'column_number' => $index,
            'entity_data' => [],
          ];
      }
    }
    $jobID = UserJob::create()->setValues([
      'job_type' => 'contribution_import',
      'status_id' => 1,
      'metadata' => [
        'submitted_values' => [
          'contactType' => $mainContactType,
          'dateFormats' => 1,
          'dataSource' => 'CRM_Import_DataSource_SQL',
          'onDuplicate' => 1,
        ],
        'Template' => ['mapping_id' => NULL],
        'DataSource' => [
          'table_name' => 'civicrm_tmp_d_abc',
          'column_headers' => array_keys($data),
          'number_of_columns' => count($data),
        ],
        'sqlQuery' => $this->getSelectQuery($data),
        'entity_configuration' => [
          'Contribution' => ['action' => 'create'],
          'Contact' => [
            'action' => 'select',
            'contact_type' => $mainContactType,
            'dedupe_rule' => $mainContactType . 'Unsupervised',
          ],
          'SoftCreditContact' => [
            'contact_type' => $mainContactType === 'Organization' ? 'Individual' : 'Organization',
            'soft_credit_type_id' => $softCreditTypeID,
            'action' => 'save',
            'entity' => ['entity_data' => ['soft_credit_type_id' => $softCreditTypeID]],
          ],
        ],
        'import_mappings' => $importMappings,
    ]])->execute()->first()['id'];
    Import::import($jobID)->execute();
  }

  /**
   * @return mixed
   * @throws \CRM_Core_Exception
   * @throws \Civi\API\Exception\UnauthorizedException
   */
  private function createSoftCreditConnectedContacts() {
    $this->createOrganization();
    // Create 2 'Jane Doe' contacts - we test it finds the second one, who has an employer relationship.
    Contact::create()->setValues([
      'contact_type' => 'Individual',
      'first_name' => 'Jane',
      'last_name' => 'Doe'
    ])->execute()->first()['id'];
    $this->ids['Individual'] = Contact::create()
      ->setValues([
        'contact_type' => 'Individual',
        'first_name' => 'Jane',
        'last_name' => 'Doe'
      ])
      ->execute()
      ->first()['id'];

    // Link the second contact by a pre-existing soft credit.
    $contributionID = Contribution::create()->setValues([
      'contact_id' => $this->ids['Organization'],
      'financial_type_id:name' => 'Donation',
      'total_amount' => 700,
    ])->execute()->first()['id'];
    ContributionSoft::create()->setValues([
      'contact_id' => $this->ids['Individual'],
      'contribution_id' => $contributionID,
      'amount' => 700,
    ])->execute()->first()['id'];
    return $contributionID;
  }

  private function createOrganization(): void {
    $this->ids['Organization'] = Contact::create()
      ->setValues([
        'contact_type' => 'Organization',
        'organization_name' => 'Trading Name'
      ])
      ->execute()
      ->first()['id'];
  }

}

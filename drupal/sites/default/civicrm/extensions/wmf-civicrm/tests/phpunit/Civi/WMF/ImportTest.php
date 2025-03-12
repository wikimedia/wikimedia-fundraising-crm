<?php

namespace Civi\WMF;

use Civi\Api4\Address;
use Civi\Api4\Contact;
use Civi\Api4\Contribution;
use Civi\Api4\ContributionSoft;
use Civi\Api4\GroupContact;
use Civi\Api4\Import;
use Civi\Api4\Relationship;
use Civi\Api4\UserJob;
use Civi\Core\Exception\DBQueryException;
use Civi\Test;
use Civi\Test\HeadlessInterface;
use Civi\Test\HookInterface;
use Civi\WMFEnvironmentTrait;
use PHPUnit\Framework\TestCase;

/**
 * Import tests for WMF user cases.
 *
 * Tips:
 *  - With HookInterface, you may implement CiviCRM hooks directly in the test
 * class. Simply create corresponding functions (e.g. "hook_civicrm_post(...)"
 * or similar).
 *
 * @group headless
 */
class ImportTest extends TestCase implements HeadlessInterface, HookInterface {

  use Test\EntityTrait;
  use Test\Api3TestTrait;
  use WMFEnvironmentTrait;

  /**
   * @var int
   */
  protected int $userJobID;

  /**
   * @var \CRM_Core_Controller|\CRM_Import_Controller
   */
  private $formController;

  /**
   * Clean up after test.
   *
   * @throws DBQueryException
   * @throws \CRM_Core_Exception
   */
  protected function tearDown(): void {
    UserJob::delete(FALSE)->addWhere('metadata', 'LIKE', '%civicrm_tmp_d_abc%')->execute();
    \CRM_Core_DAO::executeQuery('DROP TABLE IF EXISTS civicrm_tmp_d_abc');
    \Civi::cache('metadata')->delete('civiimport_table_fieldscivicrm_tmp_d_abc');
    if ($this->userJobID) {
      UserJob::delete(FALSE)->addWhere('id', '=', $this->userJobID)->execute();
    }
    Contribution::delete(FALSE)->addWhere('contact_id.nick_name', '=', 'Trading Name')->execute();
    Contribution::delete(FALSE)->addWhere('contact_id.organization_name', '=', 'Trading Name')->execute();
    Contribution::delete(FALSE)->addWhere('contact_id.last_name', '=', 'Doe')->execute();
    Contribution::delete(FALSE)->addWhere('check_number', '=', 123456)->execute();

    Contact::delete(FALSE)->addWhere('nick_name', '=', 'Trading Name')->setUseTrash(FALSE)->execute();
    Contact::delete(FALSE)->addWhere('organization_name', '=', 'Trading Name')->setUseTrash(FALSE)->execute();

    Contact::delete(FALSE)
      ->addWhere('first_name', '=', 'Jane')
      ->addWhere('last_name', '=', 'Doe')
      ->setUseTrash(FALSE)->execute();
    // Registering them all in ids['Contact'] is where the core helpers are going so
    // is preferred - we should migrate the rest over.
    $this->tearDownWMFEnvironment();
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
   *
   * @throws \CRM_Core_Exception
   */
  public function testImportOrganizationWithRelated(): void {
    $data = $this->setupImport();

    $this->createTestEntity('Contact', ['contact_type' => 'Organization', 'organization_name' => 'Long legal name', 'nick_name' => 'Trading Name'], 'organization');
    // Create 2 'Jane Doe' contacts - we test it finds the second one, who has an employer relationship.
    $this->createTestEntity('Contact', ['contact_type' => 'Individual', 'first_name' => 'Jane', 'last_name' => 'Doe'], 'jane_main');
    $this->createTestEntity('Contact', ['contact_type' => 'Individual', 'first_name' => 'Jane', 'last_name' => 'Doe', 'employer_id' => $this->ids['Contact']['organization']], 'jane_soft_credit');

    $this->runImport($data);
    $contribution = Contribution::get()->addWhere('contact_id', '=', $this->ids['Contact']['organization'])->addSelect(
      'contribution_extra.gateway',
      'contribution_extra.no_thank_you',
      'source'
    )->execute()->single();

    $this->assertEquals('USD 50.00', $contribution['source']);
    $this->assertEquals('Matching Gifts', $contribution['contribution_extra.gateway']);
    $this->assertEquals('Sent by portal (matching gift/ workplace giving)', $contribution['contribution_extra.no_thank_you']);

    $softCredits = ContributionSoft::get()->addWhere('contribution_id', '=', $contribution['id'])->execute();
    $this->assertCount(1, $softCredits);
    $softCredit = $softCredits->first();
    $this->assertEquals($this->ids['Contact']['jane_soft_credit'], $softCredit['contact_id']);
  }

  /**
   * Test importing an organization with the soft credit individual being a previous soft creditor.
   *
   * We are looking to see that
   *
   * 1) the organization can be found based on an organization_name look up
   * 2) the contact can be found based on first name & last name match + soft credit
   * 3) the relationship is created.
   *
   * @throws \CRM_Core_Exception
   */
  public function testImportOrganizationWithSoftCredit(): void {
    $data = $this->setupImport();

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
    $this->assertEquals($this->ids['Contact']['has_soft_credit'], $softCredit['contact_id']);
    // Creating the soft credit should have created a relationship.
    $relationship = Relationship::get()->addWhere('contact_id_a', '=', $this->ids['Contact']['has_soft_credit'])->execute()->first();
    $this->assertEquals($this->ids['Organization'], $relationship['contact_id_b']);
  }

  /**
   * Test duplicates are not imported.
   *
   * @throws \CRM_Core_Exception
   */
  public function testImportDuplicates(): void {
    $data = $this->setupImport(['contribution_extra__gateway_txn_id' => '123']);
    $this->fillImportRow($data);
    $this->createSoftCreditConnectedContacts();
    $this->runImport($data);
    $import = (array) Import::get($this->userJobID)->setSelect(['_status_message', '_status'])->execute();
    $this->assertEquals('soft_credit_imported', $import[0]['_status']);
    $this->assertEquals('ERROR', $import[1]['_status']);
  }

  /**
   * Test that a new contact is
   *
   * @throws \CRM_Core_Exception
   */
  public function testImportDuplicateContactMatches(): void {
    $this->createIndividual(['email_primary.email' => 'jane@example.com']);
    $this->createIndividual(['email_primary.email' => 'jane@example.com'], 'duplicate');
    $data = $this->setupImport(['organization_name' => '', 'contribution_contact_id' => '']);
    $this->runImport($data, 'Individual', 'save');
    $import = (array) Import::get($this->userJobID)
      ->setSelect(['_status_message', '_status'])
      ->execute();
    $this->assertEquals('soft_credit_imported', $import[0]['_status'], 'maybe our hack/patch got lost?');
    $contact = Contact::get(FALSE)
      ->addWhere('id', '>', $this->ids['Contact']['duplicate'])
      ->addWhere('contact_type', '=', 'Individual')
      ->execute()->single();
    $this->assertEquals('Import duplicate contact', $contact['source']);
    GroupContact::get(FALSE)
      ->addWhere('contact_id', '=', $contact['id'])
      ->addWhere('group_id:name', '=', 'imported_duplicates')
      ->addWhere('status', '=', 'Added')
      ->execute()->single();
  }

  /**
   * @throws \CRM_Core_Exception
   */
  public function testIDUsedIfPresent(): void {
    $this->createOrganization();
    $this->createTestEntity('Contact', [
      'contact_type' => 'Individual',
      'first_name' => 'Jane',
      'last_name' => 'Doe',
      'employer_id' => $this->ids['Contact']['organization'],
    ], 'individual_1');
    $this->createTestEntity('Contact', [
      'contact_type' => 'Individual',
      'first_name' => 'Jane',
      'last_name' => 'Doe',
      'employer_id' => $this->ids['Contact']['organization'],
    ], 'individual_2');
    $data = $this->setupImport(['contact_id' => ""]);
    $this->fillImportRow($data);
    $this->runImport($data);
    $import = (array) Import::get($this->userJobID)->setSelect(['_status_message', '_status'])->execute();
    $this->assertEquals('ERROR', $import[0]['_status']);
    $this->assertStringContainsString('Multiple contact matches with employer connection: ', $import[0]['_status_message']);

    // Re-run with the contact ID specified
    Import::update($this->userJobID)->setValues(['contact_id' => $this->ids['Contact']['individual_2']])->addWhere('_id', '=', 1)->execute();
    $this->runImport($data);
    $import = (array) Import::get($this->userJobID)->setSelect(['_status_message', '_status'])->execute();
    $this->assertEquals('soft_credit_imported', $import[0]['_status']);
  }

  /**
   * Test duplicates are imported for organizations.
   *
   * This reflects the likelihood that the check for one
   * organization could have been broken down into many rows with many spft credits.
   *
   * @throws \CRM_Core_Exception
   */
  public function testImportDuplicateOrganizationChecks(): void {
    $this->createOrganization();
    $data = $this->setupImport(['contribution_extra__gateway_txn_id' => '', 'check_number' => 123456]);
    $this->fillImportRow($data);
    $this->runImport($data);
    $import = (array) Import::get($this->userJobID)->setSelect(['_status_message', '_status'])->execute();
    $this->assertEquals('soft_credit_imported', $import[0]['_status']);
  }

  /**
   * Test duplicates are imported for Anonymous + check_number.
   *
   * This is because a check from an organization might be divided between
   * many individuals, more than one of whom could be anonymous.
   *
   * @throws \CRM_Core_Exception
   */
  public function testImportDuplicateAnonymous(): void {
    $this->createOrganization();
    $this->ensureAnonymousUserExists();
    $data = $this->setupImport(['contribution_extra__gateway_txn_id' => '', 'email_primary.email' => '', 'check_number' => 123456, 'first_name' => 'Anonymous', 'last_name' => 'Anonymous']);
    $this->fillImportRow($data);
    $this->runImport($data, 'Individual');
    $import = (array) Import::get($this->userJobID)->setSelect(['_status_message', '_status'])->execute();
    $this->assertEquals('soft_credit_imported', $import[0]['_status']);
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
      'email_primary.email' => 'jane@example.com',
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
   *
   * @throws \CRM_Core_Exception
   */
  public function testImportIndividualWithSoftCredit(): void {
    $data = $this->setupImport(['email_primary.email' => '']);

    $contributionID = $this->createSoftCreditConnectedContacts();

    $this->runImport($data, 'Individual');
    // The contacts have 2 contributions with soft credits - use greater than filter
    // to exclude the one that already existed.
    $contribution = Contribution::get()->addWhere('contact_id', '=', $this->ids['Contact']['has_soft_credit'])
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
    $relationship = Relationship::get()->addWhere('contact_id_a', '=', $this->ids['Contact']['has_soft_credit'])->execute()->first();
    $this->assertEquals($this->ids['Organization'], $relationship['contact_id_b']);
  }

  /**
   * Test that we can handling imports in non USD.
   *
   * @throws \CRM_Core_Exception
   */
  public function testImportFullName(): void {
    $this->createTestEntity('Contact', [
      'contact_type' => 'Individual',
      'full_name' => 'Jane Doe',
    ], 'individual_1');
    $data = $this->setupImport([
      'trxn_id' => 'abc',
      'full_name' => 'Jane Doe',
      'total_amount' => '50',
      'organization_name' => '',
      'first_name' => '',
      'last_name' => '',
    ]);

    $this->runImport($data, 'Individual');
    // The contacts have 2 contributions with soft credits - use greater than filter
    // to exclude the one that already existed.
    $contribution = Contribution::get()
      ->addWhere('trxn_id', '=', 'abc')
      ->addSelect(
        'custom.*',
        'source',
        'total_amount'
      )->execute()->first();
  }

  /**
   * Test that we can handling imports in non USD.
   *
   * @throws \CRM_Core_Exception
   */
  public function testCurrencySetsSource(): void {
    $this->createTestEntity('Contact', [
      'contact_type' => 'Individual',
      'first_name' => 'Jane',
      'last_name' => 'Doe',
      'email_primary.email' => 'jane@example.com',
    ], 'individual_1');
    $data = $this->setupImport([
      'trxn_id' => 'abc',
      'contribution_extra.original_currency' => 'PLN',
      'contribution_extra.original_amount' => '200',
      'total_amount' => '50',
      'organization_name' => '',
    ]);

    $this->runImport($data, 'Individual');
    // The contacts have 2 contributions with soft credits - use greater than filter
    // to exclude the one that already existed.
    $contribution = Contribution::get()
      ->addWhere('trxn_id', '=', 'abc')
      ->addSelect(
        'custom.*',
        'source',
        'total_amount'
      )->execute()->first();
    $this->assertEquals('PLN 200.00', $contribution['source']);
    $this->assertEquals(50, $contribution['total_amount']);
  }

  /**
   * Test that we can handling imports in non USD when the USD is not specified.
   *
   * @throws \CRM_Core_Exception
   * @throws \Exception
   */
  public function testCurrencyConversion(): void {
    $this->createTestEntity('Contact', [
      'contact_type' => 'Individual',
      'first_name' => 'Jane',
      'last_name' => 'Doe',
      'email_primary.email' => 'jane@example.com',
    ], 'individual_1');
    $data = $this->setupImport([
      'trxn_id' => 'abc',
      'contribution_extra.original_currency' => 'PLN',
      'contribution_extra.original_amount' => '200',
      'organization_name' => '',
      'total_amount' => '',
    ]);

    $this->setExchangeRate('PLN', .25);
    $this->runImport($data, 'Individual');
    // The contacts have 2 contributions with soft credits - use greater than filter
    // to exclude the one that already existed.
    $contribution = Contribution::get()
      ->addWhere('trxn_id', '=', 'abc')
      ->addSelect(
        'custom.*',
        'source',
        'total_amount'
      )->execute()->first();
    $this->assertEquals('PLN 200.00', $contribution['source']);
    $this->assertEquals(50, $contribution['total_amount']);
  }

  /**
   * Test when there are multiple individual matches.
   *
   * If there are 2 employed individuals with the same name then
   * they need merging so throw an error.
   *
   * There is some small chance they are legit - but more likely
   * this requires a merge, so it's probably ok to err on the side of
   * requiring user resolution.
   *
   * @throws \CRM_Core_Exception
   */
  public function testImportIndividualFindAmongMany(): void {
    $organizationID = (int) $this->createTestEntity('Contact', [
      'contact_type' => 'Organization',
      'organization_name' => 'The Firm',
    ])['id'];
    $this->setupImport(['organization_id' => $organizationID]);
    $this->callAPISuccess('Contact', 'create', [
      'contact_type' => 'Individual',
      'first_name' => 'Jane',
      'last_name' => 'Doe',
      'employer_id' => $organizationID,
    ]);
    $individualID2 = $this->callAPISuccess('Contact', 'create', [
      'contact_type' => 'Individual',
      'first_name' => 'Jane',
      'last_name' => 'Doe',
      'employer_id' => $organizationID,
    ])['id'];
    $this->createSoftCreditLink($organizationID, $individualID2);
    $importFields = array_fill_keys([
      'financial_type_id',
      'total_amount',
      '',
      'first_name',
      'last_name',
      'source',
      'organization_id',
    ], TRUE);
    $this->runImport($importFields, 'Individual');
    $import = Import::get($this->userJobID)->setSelect(['_status_message', '_status'])->execute()->first();
    $this->assertEquals('ERROR', $import['_status']);
    $this->assertStringContainsString('Multiple contact matches', $import['_status_message']);

    // Let's now check that if we change individual2's relationship to be disabled
    // then it will select contact 1.
    Relationship::update()->setValues(['is_active' => FALSE])->addWhere('contact_id_a', '=', $individualID2)->execute();
    $this->runImport($importFields, 'Individual');
    $import = Import::get($this->userJobID)->setSelect(['_status_message', '_status'])->execute()->first();
    $this->assertEquals('soft_credit_imported', $import['_status'], $import['_status_message']);
  }

  /**
   * @throws \CRM_Core_Exception
   */
  public function testStockSetsTimeToNoon(): void {
    $this->imitateAdminUser();
    $this->createTestEntity('Contact', [
      'contact_type' => 'Individual',
      'first_name' => 'Jane',
      'last_name' => 'Doe',
    ], 'jane_doe');
    $data = [
      'financial_type_id' => 'Stock',
      'total_amount' => 50,
      'contribution_contact_id' => $this->ids['Contact']['jane_doe'],
      'first_name' => 'Jane',
      'last_name' => 'Doe',
      'email_primary.email' => 'jane@example.com',
      'receive_date' => '2024-01-31 00:00:00',
    ];
    $this->createImportTable($data);
    $this->runImport($data, 'Individual');
    $contributions = Contribution::get()->addWhere(
      'contact_id', '=', $this->ids['Contact']['jane_doe']
    )->execute();
    $this->assertCount(1, $contributions);
    $this->assertEquals('2024-01-31 12:00:00', $contributions[0]['receive_date']);
  }

  private function getSelectQuery($columns): string {
    $columnSQL = [];
    foreach ($columns as $column => $data) {
      $columnSQL[] = "'$data' as " . str_replace('.', '__', $column);
    }
    return "SELECT " . implode(',', $columnSQL) . " FROM civicrm_contact LIMIT 1";
  }

  /**
   * Create the table that would be created on submitting the first (DataSource) form.
   *
   * @throws DBQueryException
   * @noinspection SqlResolve
   */
  protected function createImportTable($columns = []): void {
    $fieldSql = [];
    foreach (array_keys($columns) as $column) {
      $fieldSql[] = "`" . str_replace('.', '__', $column) . "` VARCHAR(255) CHARACTER SET utf8mb4 NOT NULL";
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
    $this->fillImportRow($columns);
  }

  /**
   * Emulate a logged-in user since certain functions use that.
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
   * @param string $contactAction
   *
   * @throws \API_Exception
   * @throws \CRM_Core_Exception
   * @throws \Civi\API\Exception\UnauthorizedException
   */
  private function runImport(array $data, string $mainContactType = 'Organization', string $contactAction = 'select'): void {
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

        case 'organization_id':
          $importMappings[] = [
            'name' => $mainContactType === 'Organization' ? 'id' : 'soft_credit.contact.id',
            'default_value' => NULL,
            'column_number' => $index,
            'entity_data' => $mainContactType === 'Organization' ? [] : ['soft_credit' => ['soft_credit_type_id' => $softCreditTypeID]],
          ];
          break;

        case 'contact_id':
          $columnName = 'id';
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
    $this->userJobID = UserJob::create()->setValues([
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
            'action' => $contactAction,
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
      ],
    ])->execute()->first()['id'];
    Import::import($this->userJobID)->execute();
  }

  /**
   * Test that when importing contacts with an NCOA address.
   *
   * The expectation is that any old address is re-located rather
   * than deleted. This happens using the contact import
   * as that is how we bring in DataAxle data.
   *
   * @return void
   */
  public function testContactImportAddressHook(): void {
    $contactID = $this->createIndividual(['address_primary.street_address' => 'Bumble Lane']);
    $this->doContactImport([
      'id' => $contactID,
      'custom_' . \CRM_Core_BAO_CustomField::getCustomFieldID('address_source') => 'NCOA_update',
      'custom_' . \CRM_Core_BAO_CustomField::getCustomFieldID('address_update_date') => '20240101',
      'street_address' => '123 Main St',
      'country' => 'United States',
    ]);
    $addresses = Address::get(FALSE)
      ->addWhere('contact_id', '=', $contactID)
      ->addSelect('*', 'custom.*', 'location_type_id:name')
      ->addOrderBy('is_primary', 'DESC')
      ->execute();
    $this->assertCount(2, $addresses);
    $newAddress = $addresses[0];
    $oldAddress = $addresses[1];
    $this->assertEquals('ncoa', $newAddress['address_data.address_source']);
    $this->assertEquals('123 Main St', $newAddress['street_address']);
    $this->assertEquals('Bumble Lane', $oldAddress['street_address']);
    $this->assertEquals('Old_' . date('Y'), $oldAddress['location_type_id:name']);
    $this->assertEquals(FALSE, $oldAddress['is_primary']);
  }

  private function doContactImport($data): void {
    $this->imitateAdminUser();
    $this->createImportTable($data + ['_related_contact_created' => 0, '_related_contact_matched' => 0]);
    $this->runContactImport($data);
  }

  /**
   * Helper to run the import.
   *
   * @param array $data
   * @param string $mainContactType
   */
  private function runContactImport(array $data, string $mainContactType = 'Individual'): void {
    try {
      $importMappings = [];
      $mapper = [];
      foreach (array_keys($data) as $index => $columnName) {
        $importMappings[] = [
          'name' => $columnName,
          'default_value' => NULL,
          'column_number' => $index,
          'entity_data' => [],
        ];
        $mapper[] = [$columnName];
      }
      $this->userJobID = UserJob::create()->setValues([
        'job_type' => 'contact_import',
        'status_id' => 1,
        'metadata' => [
          'submitted_values' => [
            'contactType' => $mainContactType,
            'contactSubType' => '',
            'dateFormats' => 1,
            'dataSource' => 'CRM_Import_DataSource_SQL',
            'onDuplicate' => \CRM_Import_Parser::DUPLICATE_UPDATE,
            'disableUSPS' => '',
            'doGeocodeAddress' => 1,
            'mapper' => $mapper,
          ],
          'Template' => ['mapping_id' => NULL],
          'DataSource' => [
            'table_name' => 'civicrm_tmp_d_abc',
            'column_headers' => array_keys($data),
            'number_of_columns' => count($data),
          ],
          'sqlQuery' => $this->getSelectQuery($data),
          'import_mappings' => $importMappings,
        ],
      ])->execute()->first()['id'];
      Import::import($this->userJobID)->execute();
    }
    catch (DBQueryException $e) {
      $this->fail('query error ' . $e->getMessage() . $e->getSQL());
    }
  }

  /**
   * @return int
   * @throws \CRM_Core_Exception
   */
  private function createSoftCreditConnectedContacts(): int {
    $this->createOrganization();
    // Create 2 'Jane Doe' contacts - we test it finds the second one, who has an employer relationship.
    $this->createTestEntity('Contact', [
      'contact_type' => 'Individual',
      'first_name' => 'Jane',
      'last_name' => 'Doe',
    ], 'no_soft_credit');
    $this->createTestEntity('Contact', [
      'contact_type' => 'Individual',
      'first_name' => 'Jane',
      'last_name' => 'Doe',
    ], 'has_soft_credit');
    return $this->createSoftCreditLink($this->ids['Contact']['organization'], $this->ids['Contact']['has_soft_credit']);
  }

  private function createOrganization(): void {
    $this->ids['Organization'] = $this->createTestEntity('Contact', [
      'contact_type' => 'Organization',
      'organization_name' => 'Trading Name',
    ], 'organization')['id'];
  }

  /**
   * @param array $data
   * @return array
   * @throws DBQueryException
   */
  protected function setupImport(array $data = []): array {
    $this->imitateAdminUser();
    $data = array_merge([
      'financial_type_id' => 'Engage',
      'total_amount' => 50,
      'organization_name' => 'Trading Name',
      'first_name' => 'Jane',
      'last_name' => 'Doe',
      'email_primary.email' => 'jane@example.com',
    ], $data);
    $this->createImportTable($data);
    return $data;
  }

  /**
   * @param int $organizationID
   * @param int $individualID
   *
   * @return int
   * @throws \CRM_Core_Exception
   */
  private function createSoftCreditLink(int $organizationID, int $individualID): int {
    // Link the second contact by a pre-existing soft credit.
    $contributionID = Contribution::create()->setValues([
      'contact_id' => $organizationID,
      'financial_type_id:name' => 'Donation',
      'total_amount' => 700,
    ])->execute()->first()['id'];
    ContributionSoft::create()->setValues([
      'contact_id' => $individualID,
      'contribution_id' => $contributionID,
      'amount' => 700,
    ])->execute()->first()['id'];
    return $contributionID;
  }

  /**
   * @param $columns
   *
   * @throws DBQueryException
   */
  protected function fillImportRow($columns): void {
    \CRM_Core_DAO::executeQuery('INSERT INTO civicrm_tmp_d_abc (' . str_replace('.', '__', implode(',', array_keys($columns))) . ') ' . $this->getSelectQuery($columns));
  }

  /**
   * @throws \CRM_Core_Exception
   */
  protected function ensureAnonymousUserExists(): void {
    if (!\Civi\WMFHelper\Contact::getAnonymousContactID()) {
      $this->ids['Contact']['anonymous'] = Contact::create([
        'first_name' => 'Anonymous',
        'last_name' => 'Anonymous',
        'contact_type' => 'Individual',
        'email_primary.email' => 'fakeemail@wikimedia.org',
      ])->execute();
    }
  }

  /**
   * This tests some features for the Fidelity import:
   *  - Fidelity: The Donor Advised Organization is created from the Contact
   * reference field.
   *  - General: is_out_out is handled.
   *
   * @throws \CRM_Core_Exception
   *
   * @return void
   */
  public function testImportFeatures(): void {
    $importRows = $this->importCSV('fidelity.csv', [
      [],
      [],
      [],
      ['name' => 'receive_date'],
      ['name' => 'total_amount'],
      [],
      ['name' => 'donor_advised_fund.owns_donor_advised_for'],
      ['name' => 'full_name', 'default_value' => 'Squeaky Mouse'],
      ['name' => 'address_primary.street_address'],
      [],
      ['name' => 'financial_type_id', 'default_value' => 'Engage'],
    ], [
      'dateFormats' => \CRM_Utils_Date::DATE_mm_dd_yy,
    ]);
    $contribution = Contribution::get(FALSE)
      ->addSelect('contact_id', 'donor_advised_fund.owns_donor_advised_for')
      ->addWhere('id', '=', $importRows[1]['_entity_id'])
      ->execute()->single();
    $relationship = Relationship::get(FALSE)
      ->addSelect('relationship_type_id:name')
      ->addWhere('contact_id_a', '=', $contribution['donor_advised_fund.owns_donor_advised_for'])
      ->addWhere('contact_id_b', '=', $contribution['contact_id'])
      ->execute()->single();
    $this->assertEquals('Holds a Donor Advised Fund of', $relationship['relationship_type_id:name']);
  }

  /**
   * Import the csv file values.
   *
   * This function uses a flow that mimics the UI flow.
   *
   * @param string $csv Name of csv file.
   * @param array $fieldMappings
   * @param array $submittedValues
   */
  protected function importCSV(string $csv, array $fieldMappings, array $submittedValues = []): array {
    try {
      \Civi::dispatcher()->addListener('hook_civicrm_alterRedirect', [$this, 'avoidRedirect']);
      $this->imitateAdminUser();
      $submittedValues = array_merge([
        'skipColumnHeader' => TRUE,
        'fieldSeparator' => ',',
        'contactType' => 'Individual',
        'mapper' => $this->getMapperFromFieldMappings($fieldMappings),
        'dataSource' => 'CRM_Import_DataSource_CSV',
        'file' => ['name' => $csv],
        'dateFormats' => \CRM_Utils_Date::DATE_yyyy_mm_dd,
        'onDuplicate' => \CRM_Import_Parser::DUPLICATE_SKIP,
        'groups' => [],
      ], $submittedValues);
      $this->submitDataSourceForm($csv, $submittedValues);

      $userJob = UserJob::get(FALSE)
        ->addWhere('id', '=', $this->userJobID)
        ->execute()->single();
      $userJob['metadata']['entity_configuration'] = [
        'Contribution' => ['action' => 'create'],
        "Contact" => [
          "action" => "save",
          "contact_type" => "Individual",
          "dedupe_rule" => 'IndividualGeneral',
        ],
      ];
      $userJob['metadata']['import_mappings'] = $fieldMappings;
      foreach ($userJob['metadata']['import_mappings'] as $key => $value) {
        if (!isset($value['column_number'])) {
          $userJob['metadata']['import_mappings'][$key]['column_number'] = $key;
        }
      }
      UserJob::update(FALSE)
        ->setValues(['metadata' => $userJob['metadata']])
        ->addWhere('id', '=', $this->userJobID)
        ->execute();
      $form = $this->getMapFieldForm($submittedValues);
      $form->setUserJobID($this->userJobID);
      $form->buildForm();
      $this->assertTrue($form->validate(), print_r($form->_errors, TRUE));
      $form->postProcess();
      $this->submitPreviewForm($submittedValues);
      return $this->getDataSource()->getRows(FALSE);
    }
    catch (\CRM_Core_Exception $e) {
      $this->fail($e->getMessage());
    }
  }

  public static function avoidRedirect($parsedUrl, &$context) {
    throw new \CRM_Core_Exception_PrematureExitException();
  }

  /**
   * Submit the preview form, triggering the import.
   *
   * @param array $submittedValues
   */
  protected function submitPreviewForm(array $submittedValues): void {
    $form = $this->getPreviewForm($submittedValues);
    $form->setUserJobID($this->userJobID);
    $form->buildForm();
    $this->assertTrue($form->validate());
    $this->originalSettings['enableBackgroundQueue'] = \Civi::settings()
      ->get('enableBackgroundQueue');
    \Civi::settings()->set('enableBackgroundQueue', 1);
    try {
      $form->postProcess();
      $this->fail('Exception expected');
    }
    catch (\CRM_Core_Exception_PrematureExitException $e) {
      $queue = \Civi::queue('user_job_' . $this->userJobID);
      $this->assertGreaterThan(0, \CRM_Core_DAO::singleValueQuery('SELECT COUNT(*) FROM civicrm_queue_item'), 'items are not queued, they may have failed validation');
      $runner = new \CRM_Queue_Runner([
        'queue' => $queue,
        'errorMode' => \CRM_Queue_Runner::ERROR_ABORT,
      ]);
      $result = $runner->runAll();
      $this->assertTrue($result, $result === TRUE ? '' : \CRM_Core_Error::formatTextException($result['exception']));
    }
  }

  /**
   * @param array $mappings
   *
   * @return array
   */
  protected function getMapperFromFieldMappings(array $mappings): array {
    $mapper = [];
    foreach ($mappings as $mapping) {
      $fieldInput = [$mapping['name'] ?? ''];
      if (!empty($mapping['soft_credit_type_id'])) {
        $fieldInput[1] = $mapping['soft_credit_type_id'];
      }
      $mapper[] = $fieldInput;
    }
    return $mapper;
  }

  /**
   * @return \CRM_Import_DataSource
   */
  protected function getDataSource(): \CRM_Import_DataSource {
    return new \CRM_Import_DataSource_CSV($this->userJobID);
  }

  /**
   * Submit the data source form.
   *
   * @param string $csv
   * @param array $submittedValues
   */
  protected function submitDataSourceForm(string $csv, array $submittedValues): void {
    $directory = __DIR__ . '/../../..';
    $submittedValues = array_merge([
      'uploadFile' => ['name' => $directory . '/data/' . $csv],
      'skipColumnHeader' => TRUE,
      'fieldSeparator' => ',',
      'contactType' => 'Individual',
      'dataSource' => 'CRM_Import_DataSource_CSV',
      'file' => ['name' => $csv],
      'dateFormats' => \CRM_Utils_Date::DATE_yyyy_mm_dd,
      'onDuplicate' => \CRM_Import_Parser::DUPLICATE_SKIP,
      'groups' => [],
    ], $submittedValues);
    $form = $this->getDataSourceForm($submittedValues);
    $values = $_SESSION['_' . $form->controller->_name . '_container']['values'];
    $form->buildForm();
    $form->postProcess();
    $this->userJobID = $form->getUserJobID();
    // This gets reset in DataSource so re-do....
    $_SESSION['_' . $form->controller->_name . '_container']['values'] = $values;
  }

  /**
   * Get the import's datasource form.
   *
   * Defaults to contribution - other classes should override.
   *
   * @param array $submittedValues
   *
   * @return \CRM_Contribute_Import_Form_DataSource
   */
  protected function getDataSourceForm(array $submittedValues): \CRM_Contribute_Import_Form_DataSource {
    return $this->getFormObject('CRM_Contribute_Import_Form_DataSource', $submittedValues);
  }

  /**
   * Get the import's mapField form.
   *
   * Defaults to contribution - other classes should override.
   *
   * @param array $submittedValues
   *
   * @return \CRM_Contribute_Import_Form_MapField
   */
  protected function getMapFieldForm(array $submittedValues): \CRM_Contribute_Import_Form_MapField {
    /** @var \CRM_Contribute_Import_Form_MapField $form */
    $form = $this->getFormObject('CRM_Contribute_Import_Form_MapField', $submittedValues);
    return $form;
  }

  /**
   * Get the import's preview form.
   *
   * Defaults to contribution - other classes should override.
   *
   * @param array $submittedValues
   *
   * @return \CRM_Contribute_Import_Form_Preview
   */
  protected function getPreviewForm(array $submittedValues): \CRM_Contribute_Import_Form_Preview {
    /** @var \CRM_Contribute_Import_Form_Preview $form */
    $form = $this->getFormObject('CRM_Contribute_Import_Form_Preview', $submittedValues);
    return $form;
  }

  /**
   * Instantiate form object.
   *
   * We need to instantiate the form to run preprocess, which means we have to trick it about the request method.
   *
   * @param string $class
   *   Name of form class.
   *
   * @param array $formValues
   *
   * @param array $urlParameters
   *
   * @return \CRM_Contribute_Import_Form_DataSource|\CRM_Contribute_Import_Form_MapField|\CRM_Contribute_Import_Form_Preview
   */
  public function getFormObject(string $class, array $formValues = [], array $urlParameters = []) {
    try {
      $_POST = $formValues;
      /** @var \CRM_Contribute_Import_Form_DataSource|\CRM_Contribute_Import_Form_MapField|\CRM_Contribute_Import_Form_Preview $form */
      $form = new $class();
      $_SERVER['REQUEST_METHOD'] = 'GET';
      $_REQUEST += $urlParameters;
      if ($this->formController) {
        // Add to the existing form controller.
        $form->controller = $this->formController;
      }
      else {
        $form->controller = new \CRM_Import_Controller('import contributions', ['entity' => 'Contribution']);
        $form->controller->setStateMachine(new \CRM_Core_StateMachine($form->controller));
        $this->formController = $form->controller;
      }
      // The submitted values should be set on one or the other of the forms in the flow.
      // For test simplicity we set on all rather than figuring out which ones go where....
      $_SESSION['_' . $form->controller->_name . '_container']['values']['DataSource'] = $formValues;
      $_SESSION['_' . $form->controller->_name . '_container']['values']['MapField'] = $formValues;
      $_SESSION['_' . $form->controller->_name . '_container']['values']['Preview'] = $formValues;
      return $form;
    }
    catch (\CRM_Core_Exception $e) {
      $this->fail('unable to construct form ' . $e->getMessage());
    }
  }

}

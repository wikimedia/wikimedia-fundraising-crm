<?php

use Civi\Api4\Contribution;

/**
 * @group Import
 * @group Offline2Civicrm
 *
 * Refer to this comment in Phab for rules about when contacts are created.
 *   These are the rules the tests are working to (and both should be updated
 *   to reflect rule changes).
 *   https://phabricator.wikimedia.org/T115044#3012232
 */
class BenevityTest extends BaseChecksFileTest {

  /**
   * Gateway.
   *
   * eg. benevity, engage etc.
   *
   * @var string
   */
  protected $gateway = 'benevity';

  public function setUp(): void {
    $this->ensureAnonymousContactExists();
    parent::setUp();

    $this->setExchangeRates($this->epochtime, ['USD' => 1, 'BTC' => 3]);
    $countries = $this->callAPISuccess('Country', 'get', ['options' => ['limit' => 0]]);
    $this->callAPISuccess('Setting', 'create', ['countryLimit' => array_keys($countries['values'])]);
    $this->callAPISuccess('Setting', 'create', ['provinceLimit' => []]);
  }

  /**
   * Delete created Benevity contributions and contacts.
   *
   * @throws \CRM_Core_Exception
   * @throws \Civi\API\Exception\UnauthorizedException
   */
  public function tearDown(): void {
    $benevityContributions = Contribution::get()->addWhere('trxn_id', 'LIKE', 'BENEVITY%')->setCheckPermissions(FALSE)->setSelect(['id'])->execute();
    foreach ($benevityContributions as $contribution) {
      $this->cleanupContribution($contribution['id']);
    }
    CRM_Core_DAO::executeQuery("
      DELETE c FROM civicrm_contact c LEFT JOIN civicrm_email e ON c.id = e.contact_id
      WHERE organization_name IN('Donald Duck Inc', 'Mickey Mouse Inc', 'Goofy Inc', 'Uncle Scrooge Inc')
      OR nick_name IN('Donald Duck Inc', 'Mickey Mouse Inc', 'Goofy Inc', 'Uncle Scrooge Inc')
      OR first_name = 'Minnie' AND last_name = 'Mouse'
      OR first_name = 'Pluto'
      OR first_name = 'Hewey' AND last_name = 'Duck'
      OR email = 'uncle@duck.org'
    ");
    parent::tearDown();
  }

  /**
   * Test that all imports fail if the organization has multiple matches.
   */
  public function testImportFailOrganizationContactAmbiguous(): void {
    $this->callAPISuccess('Contact', 'create', [
      'organization_name' => 'Donald Duck Inc',
      'contact_type' => 'Organization',
    ]);
    $this->callAPISuccess('Contact', 'create', [
      'organization_name' => 'Donald Duck Inc',
      'contact_type' => 'Organization',
    ]);
    $messages = $this->importBenevityFile();
    $this->assertEquals('0 out of 5 rows were imported.', $messages['Result']);
  }

  /**
   * Test that all imports fail if the organization does not pre-exist.
   */
  public function testImportFailNoOrganizationContactExists(): void {
    $messages = $this->importBenevityFile();
    $this->assertEquals('0 out of 5 rows were imported.', $messages['Result']);
  }

  /**
   * Test that import passes for the contact if a single match is found.
   */
  public function testImportSucceedOrganizationSingleContactExists(): void {
    $this->createTestEntity('Contact', [
      'organization_name' => 'Donald Duck Inc',
      'contact_type' => 'Organization',
    ]);
    $messages = $this->importBenevityFile();
    $this->assertEquals('1 out of 5 rows were imported.', $messages['Result']);
  }

  /**
   * Test that import passes for the Individual contact if a single match is
   * found.
   */
  public function testImportSucceedIndividualSingleContactExists(): void {
    $thaMouseMeister = $this->createTestEntity('Contact', [
      'organization_name' => 'Mickey Mouse Inc',
      'contact_type' => 'Organization',
    ]);
    $minnie = $this->createTestEntity('Contact', [
      'first_name' => 'Minnie',
      'last_name' => 'Mouse',
      'contact_type' => 'Individual',
      'email_primary.email' => 'minnie@mouse.org',
    ]);
    $messages = $this->importBenevityFile();
    $this->assertEquals('1 out of 5 rows were imported.', $messages['Result']);
    $contributions = $this->callAPISuccess('Contribution', 'get', ['contact_id' => $minnie['id']]);
    $this->assertEquals(1, $contributions['count']);
    $relationships = $this->callAPISuccess('Relationship', 'get', [
      'contact_id_a' => $minnie['id'],
      'contact_id_b' => $thaMouseMeister['id'],
    ]);
    $this->assertEquals(1, $relationships['count']);
    $email = $this->callAPISuccessGetSingle('Email', ['contact_id' => $minnie['id']]);
    $this->assertNotEmpty($email['location_type_id']);
  }

  /**
   * Test that import passes for the Individual contact when no single match is
   * found.
   *
   * In this scenario an email exists so a contact is created. The
   * organization exists and can be matched, however the individual does not
   * exist & should be created.
   */
  public function testImportSucceedIndividualNoExistingMatch(): void {
    $thaMouseMeister = $this->createTestEntity('Contact', [
      'organization_name' => 'Mickey Mouse Inc',
      'contact_type' => 'Organization',
    ]);
    $messages = $this->importBenevityFile();
    $this->assertEquals('1 out of 5 rows were imported.', $messages['Result']);
    $contribution = $this->callAPISuccessGetSingle('Contribution', ['trxn_id' => 'BENEVITY TRXN-SQUEAK']);
    $this->assertEquals('Cash', $contribution['financial_type']);
    $relationships = $this->callAPISuccess('Relationship', 'get', [
        'contact_id_a' => $contribution['contact_id'],
        'contact_id_b' => $thaMouseMeister['id'],
      ]
    );
    $this->assertEquals(1, $relationships['count']);
    $minnie = $this->callAPISuccessGetSingle('Contact', [
      'id' => $contribution['contact_id'],
      'return' => 'email',
    ]);
    $this->assertEquals('minnie@mouse.org', $minnie['email']);
    $email = $this->callAPISuccessGetSingle('Email', ['contact_id' => $minnie['id']]);
    $this->assertNotEmpty($email['location_type_id']);
  }

  /**
   * Test that import works when creating a contact just for the matching gift.
   *
   * In this scenario an email exists so a contact is created. The contact does
   * not make a donation but is soft credited the organisation's donation.
   *
   * @throws \League\Csv\Exception
   * @throws \Civi\WMFException\WMFException
   */
  public function testImportSucceedIndividualNoExistingMatchOnlyMatchingGift(): void {
    $thaMouseMeister = $this->createTestEntity('Contact', [
      'organization_name' => 'Mickey Mouse Inc',
      'contact_type' => 'Organization',
    ]);
    $relationships = $this->callAPISuccess('Relationship', 'get', [
      'contact_id_b' => $thaMouseMeister['id'],
    ]);
    $this->assertEquals(0, $relationships['count']);

    $importer = new BenevityFile($this->getCsvDirectory() . "benevity_only_match.csv", ['date' => ['year' => 2019, 'month' => 9, 'day' => 12]]);
    $importer->import();
    $messages = $importer->getMessages();
    $this->assertEquals('All rows were imported', $messages['Result']);
    $contribution = $this->callAPISuccessGetSingle('Contribution', ['trxn_id' => 'BENEVITY TRXN-SQUEAK_MATCHED']);
    $relationship = $this->callAPISuccessGetSingle('Relationship', [
      'contact_id_b' => $thaMouseMeister['id'],
    ]);
    $this->assertEquals($relationship['contact_id_a'], $contribution['soft_credit_to']);
  }

  /**
   * Test when creating a contact just for the matching gift on a soft credit
   * match.
   *
   * In this scenario the contact is matched based on a prior soft credit.
   * Their
   * email is ignored to make this match.
   *
   * The contact does not make a donation but is soft credited the
   * organisation's donation.
   *
   * We are checking the relationship is created.
   *
   * @throws \League\Csv\Exception
   * @throws \Civi\WMFException\WMFException
   */
  public function testImportSucceedIndividualSofCreditMatchMatchingGiftNoDonorGift(): void {
    $thaMouseMeister = $this->createTestEntity('Contact', [
      'organization_name' => 'Mickey Mouse Inc',
      'contact_type' => 'Organization',
    ]);
    $minnie = $this->createTestEntity('Contact', [
      'first_name' => 'Minnie',
      'last_name' => 'Mouse',
      'contact_type' => 'Individual',
      'email_primary.email' => 'minnie@mouse_home.org',
    ]);
    // Create a contribution on the organisation, soft credited to Better Minnie.
    $this->callAPISuccess('Contribution', 'create', [
      'total_amount' => 4,
      'financial_type_id' => 'Donation',
      'soft_credit_to' => $minnie['id'],
      'contact_id' => $thaMouseMeister['id'],
    ]);

    $importer = new BenevityFile($this->getCsvDirectory() . "/benevity_only_match.csv", ['date' => ['year' => 2019, 'month' => 9, 'day' => 12]]);
    $importer->import();
    $messages = $importer->getMessages();
    $this->assertEquals('All rows were imported', $messages['Result']);
    $contribution = $this->callAPISuccessGetSingle('Contribution', ['trxn_id' => 'BENEVITY TRXN-SQUEAK_MATCHED']);
    $relationship = $this->callAPISuccessGetSingle('Relationship', [
      'contact_id_b' => $thaMouseMeister['id'],
    ]);
    $this->assertEquals($relationship['contact_id_a'], $contribution['soft_credit_to']);
  }

  /**
   * Test that import resolves ambiguous individuals by choosing based on the
   * employer.
   */
  public function testImportSucceedIndividualDisambiguateByEmployer(): void {
    $organization = $this->createTestEntity('Contact', [
      'organization_name' => 'Mickey Mouse Inc',
      'contact_type' => 'Organization',
    ]);
    $minnie = $this->createTestEntity('Contact', [
      'first_name' => 'Minnie',
      'last_name' => 'Mouse',
      'contact_type' => 'Individual',
      'email_primary.email' => 'minnie@mouse.org',
    ]);
    $betterMinnie = $this->createTestEntity('Contact', [
      'first_name' => 'Minnie',
      'last_name' => 'Mouse',
      'contact_type' => 'Individual',
      'email_primary.email' => 'minnie@mouse.org',
      'employer_id' => $organization['id'],
    ]);
    $messages = $this->importBenevityFile();
    $this->assertEquals('1 out of 5 rows were imported.', $messages['Result']);
    $contributions = $this->callAPISuccess('Contribution', 'get', ['contact_id' => $minnie['id']]);
    $this->assertEquals(0, $contributions['count']);

    $contributions = $this->callAPISuccess('Contribution', 'get', ['contact_id' => $betterMinnie['id']]);
    $this->assertEquals(1, $contributions['count']);
    $relationships = $this->callAPISuccess('Relationship', 'get', [
      'contact_id_a' => $betterMinnie['id'],
      'contact_id_b' => $organization['id'],
    ]);
    $this->assertEquals(1, $relationships['count']);
  }

  /**
   * Test that import resolves ambiguous individuals by choosing based on the
   * employer.
   */
  public function testImportSucceedIndividualDisambiguateByEmployerEmailAdded(): void {
    $organization = $this->createTestEntity('Contact', [
      'organization_name' => 'Mickey Mouse Inc',
      'contact_type' => 'Organization',
    ]);
    $minnie = $this->createTestEntity('Contact', [
      'first_name' => 'Minnie',
      'last_name' => 'Mouse',
      'contact_type' => 'Individual',
    ]);
    $betterMinnie = $this->createTestEntity('Contact', [
      'first_name' => 'Minnie',
      'last_name' => 'Mouse',
      'contact_type' => 'Individual',
      'employer_id' => $organization['id'],
    ]);
    $messages = $this->importBenevityFile();
    $this->assertEquals('1 out of 5 rows were imported.', $messages['Result']);
    $contributions = $this->callAPISuccess('Contribution', 'get', ['contact_id' => $minnie['id']]);
    $this->assertEquals(0, $contributions['count']);

    $contributions = $this->callAPISuccess('Contribution', 'get', ['contact_id' => $betterMinnie['id']]);
    $this->assertEquals(1, $contributions['count']);
    $relationships = $this->callAPISuccess('Relationship', 'get', [
      'contact_id_a' => $betterMinnie['id'],
      'contact_id_b' => $organization['id'],
    ]);
    $this->assertEquals(1, $relationships['count']);
    $this->callAPISuccessGetSingle('Email', ['email' => 'minnie@mouse.org']);
  }

  /**
   * Test that import creates new contacts when it can't resolve to a single
   * contact.
   *
   * @throws \League\Csv\Exception
   * @throws \Civi\WMFException\WMFException
   */
  public function testImportSucceedIndividualTooManyChoicesCantDecideSpamTheDB(): void {
    $organization = $this->createTestEntity('Contact', [
      'organization_name' => 'Mickey Mouse Inc',
      'contact_type' => 'Organization',
    ]);
    $minnie = $this->createTestEntity('Contact', [
      'first_name' => 'Minnie',
      'last_name' => 'Mouse',
      'contact_type' => 'Individual',
      'email_primary.email' => 'minnie@mouse.org',
    ]);
    $doppelgangerMinnie = $this->createTestEntity('Contact', [
      'first_name' => 'Minnie',
      'last_name' => 'Mouse',
      'contact_type' => 'Individual',
      'email_primary.email' => 'minnie@mouse.org',
    ]);
    $importer = new BenevityFile($this->getCsvDirectory() . "benevity_mice_no_email.csv", ['date' => ['year' => 2019, 'month' => 9, 'day' => 12]]);
    $importer->import();
    $messages = $importer->getMessages();
    $this->assertEquals('All rows were imported', $messages['Result']);

    // All you Minnie's are not the real Minnie
    $contributions = $this->callAPISuccess('Contribution', 'get', ['contact_id' => $minnie['id']]);
    $this->assertEquals(0, $contributions['count']);
    $contributions = $this->callAPISuccess('Contribution', 'get', ['contact_id' => $doppelgangerMinnie['id']]);
    $this->assertEquals(0, $contributions['count']);

    // Will the Real Minnie Mouse Please stand up.
    $relationship = $this->callAPISuccessGetSingle('Relationship', ['contact_id_b' => $organization['id']]);
    $this->assertNotEquals($minnie['id'], $relationship['contact_id_a']);
    $this->assertNotEquals($doppelgangerMinnie['id'], $relationship['contact_id_a']);

    $contributions = $this->callAPISuccess('Contribution', 'get', ['contact_id' => $relationship['contact_id_a']]);
    $this->assertEquals(2, $contributions['count']);
  }

  /**
   * Test that import resolves ambiguous individuals by choosing based on the
   * employer where nick_name match in play.
   */
  public function testImportSucceedIndividualDisambiguateByEmployerNickName(): void {
    $organization = $this->createTestEntity('Contact', [
      'organization_name' => 'Mice',
      'nick_name' => 'Mickey Mouse Inc',
      'contact_type' => 'Organization',
    ]);
    $minnie = $this->createTestEntity('Contact', [
      'first_name' => 'Minnie',
      'last_name' => 'Mouse',
      'contact_type' => 'Individual',
      'email' => 'minnie@mouse.org',
    ]);
    $betterMinnie = $this->createTestEntity('Contact', [
      'first_name' => 'Minnie',
      'last_name' => 'Mouse',
      'contact_type' => 'Individual',
      'email_primary.email' => 'minnie@mouse.org',
      'employer_id' => $organization['id'],
    ]);
    $messages = $this->importBenevityFile();
    $this->assertEquals('1 out of 5 rows were imported.', $messages['Result']);
    $contributions = $this->callAPISuccess('Contribution', 'get', ['contact_id' => $minnie['id']]);
    $this->assertEquals(0, $contributions['count']);

    $contributions = $this->callAPISuccess('Contribution', 'get', ['contact_id' => $betterMinnie['id']]);
    $this->assertEquals(1, $contributions['count']);
  }

  /**
   * Test that import resolves ambiguous individuals based on previous soft
   * credit history.
   *
   * If an organisation has previously soft credited an individual we consider
   * that to be equivalent to an employer relationship having been formed.
   *
   * Probably longer term the employment relationships will exist and this will
   * be redundant.
   */
  public function testImportSucceedIndividualDisambiguateByPreviousSoftCredit(): void {
    $organization = $this->createTestEntity('Contact', [
      'organization_name' => 'Mickey Mouse Inc',
      'contact_type' => 'Organization',
    ]);
    $minnie = $this->createTestEntity('Contact', [
      'first_name' => 'Minnie',
      'last_name' => 'Mouse',
      'contact_type' => 'Individual',
      'email_primary.email' => 'minnie@mouse.org',
    ]);
    $betterMinnie = $this->createTestEntity('Contact', [
      'first_name' => 'Minnie',
      'last_name' => 'Mouse',
      'contact_type' => 'Individual',
      'email_primary.email' => 'minnie@mouse.org',
    ]);
    // Create a contribution on the organisation, soft credited to Better Minnie.
    $this->callAPISuccess('Contribution', 'create', [
      'total_amount' => 4,
      'financial_type_id' => 'Donation',
      'soft_credit_to' => $betterMinnie['id'],
      'contact_id' => $organization['id'],
    ]);
    $messages = $this->importBenevityFile();
    $this->assertEquals('1 out of 5 rows were imported.', $messages['Result']);
    $contributions = $this->callAPISuccess('Contribution', 'get', ['contact_id' => $minnie['id']]);
    $this->assertEquals(0, $contributions['count']);

    $contributions = $this->callAPISuccess('Contribution', 'get', ['contact_id' => $betterMinnie['id']]);
    $this->assertEquals(1, $contributions['count']);
    $relationships = $this->callAPISuccess('Relationship', 'get', [
      'contact_id_a' => $betterMinnie['id'],
      'contact_id_b' => $organization['id'],
    ]);
    $this->assertEquals(1, $relationships['count']);
  }

  /**
   * Test that import creates new if there are multiple choices based on
   * previous soft credit history.
   *
   * If we try to disambiguate our contact using soft credit history and there
   * is more than one match, we give up & create a new one. In future this one
   * should get used as it will have an employee relationship.
   */
  public function testImportSucceedIndividualCreateIfAmbiguousPreviousSoftCredit(): void {
    $organization = $this->createTestEntity('Contact', [
      'organization_name' => 'Mickey Mouse Inc',
      'contact_type' => 'Organization',
    ]);
    $minnie = $this->createTestEntity('Contact', [
      'first_name' => 'Minnie',
      'last_name' => 'Mouse',
      'contact_type' => 'Individual',
      'email' => 'minnie@mouse.org',
    ]);
    $betterMinnie = $this->createTestEntity('Contact', [
      'first_name' => 'Minnie',
      'last_name' => 'Mouse',
      'contact_type' => 'Individual',
      'email' => 'minnie@mouse.org',
    ]);
    foreach ([$minnie, $betterMinnie] as $mouse) {
      // Create a contribution on the organisation, soft credited to each mouse..
      $this->callAPISuccess('Contribution', 'create', [
        'total_amount' => 4,
        'financial_type_id' => 'Donation',
        'soft_credit_to' => $mouse['id'],
        'contact_id' => $organization['id'],
      ]);
    }

    $messages = $this->importBenevityFile();
    $this->assertEquals('1 out of 5 rows were imported.', $messages['Result']);
    $contributions = $this->callAPISuccess('Contribution', 'get', [
      'contact_id' => [
        'IN' => [
          $minnie['id'],
          $betterMinnie['id'],
        ],
      ],
    ]);
    $this->assertEquals(0, $contributions['count']);

    $newestMouse = $this->callAPISuccessGetSingle('Contact', [
      'id' => ['NOT IN' => [$minnie['id'], $betterMinnie['id']]],
      'first_name' => 'Minnie',
      'last_name' => 'Mouse',
    ]);
    $contributions = $this->callAPISuccess('Contribution', 'get', ['contact_id' => $newestMouse['id']]);
    $this->assertEquals(1, $contributions['count']);
    $relationships = $this->callAPISuccess('Relationship', 'get', [
      'contact_id_a' => $newestMouse['id'],
      'contact_id_b' => $organization['id'],
    ]);
    $this->assertEquals(1, $relationships['count']);
  }

  /**
   * Test that import resolves ambiguous individuals preferring relationships
   * over soft credits.
   *
   * We resolve ambiguous contacts by choosing one previously linked to the
   * employer. If there is more than one that is linked by 'is employed by' or
   * 'has been previously soft credited' then we prefer the one with an
   * employee relationship.
   */
  public function testImportSucceedIndividualPreferRelationshipOverPreviousSoftCredit(): void {
    $organization = $this->createTestEntity('Contact', [
      'organization_name' => 'Mickey Mouse Inc',
      'contact_type' => 'Organization',
    ]);
    $minnie = $this->createTestEntity('Contact', [
      'first_name' => 'Minnie',
      'last_name' => 'Mouse',
      'contact_type' => 'Individual',
      'email_primary.email' => 'minnie@mouse.org',
    ]);
    $betterMinnie = $this->createTestEntity('Contact', [
      'first_name' => 'Minnie',
      'last_name' => 'Mouse',
      'contact_type' => 'Individual',
      'email_primary.email' => 'minnie@mouse.org',
      'employer_id' => $organization['id'],
    ]);

    // Create a contribution on the organisation, soft credited to each minne.
    $this->callAPISuccess('Contribution', 'create', [
      'total_amount' => 4,
      'financial_type_id' => 'Donation',
      'soft_credit_to' => $minnie['id'],
      'contact_id' => $organization['id'],
    ]);

    // But betterMinnie has a relationship, she wins.

    $messages = $this->importBenevityFile();
    $this->assertEquals('1 out of 5 rows were imported.', $messages['Result']);
    $contributions = $this->callAPISuccess('Contribution', 'get', ['contact_id' => $minnie['id']]);
    $this->assertEquals(0, $contributions['count']);

    $this->callAPISuccessGetSingle('Contribution', ['contact_id' => $betterMinnie['id']]);
  }

  /**
   * Test that we will accept a name match for employees, even when there is an
   * email mis-match.
   *
   * We have a situation where employees are often in the database with a
   * different email than in the Benevity import (e.g a personal email). If
   * there is already a contact with the same first and last name and they have
   * been related to the organization (by an employer relationship or a
   * previous soft credit) we should accept them.
   */
  public function testImportSucceedIndividualMatchToEmployerDisregardingEmail(): void {
    $organization = $this->createTestEntity('Contact', [
      'organization_name' => 'Mickey Mouse Inc',
      'contact_type' => 'Organization',
    ]);
    $betterMinnie = $this->createTestEntity('Contact', [
      'first_name' => 'Minnie',
      'last_name' => 'Mouse',
      'contact_type' => 'Individual',
      'email_primary.email' => 'minnie@mouse_home.org',
      'employer_id' => $organization['id'],
    ]);
    $messages = $this->importBenevityFile();
    $this->assertEquals('1 out of 5 rows were imported.', $messages['Result']);

    $contributions = $this->callAPISuccess('Contribution', 'get', ['contact_id' => $betterMinnie['id']]);
    $this->assertEquals(1, $contributions['count']);
    $relationships = $this->callAPISuccess('Relationship', 'get', [
      'contact_id_a' => $betterMinnie['id'],
      'contact_id_b' => $organization['id'],
    ]);
    $this->assertEquals(1, $relationships['count']);
    $emails = $this->callAPISuccess('Email', 'get', [
      'contact_id' => $betterMinnie['id'],
      'sequential' => 1,
    ]);
    $this->assertEquals(2, $emails['count']);
    $this->assertEquals(1, $emails['values'][0]['is_primary']);
    $this->assertEquals('minnie@mouse_home.org', $emails['values'][0]['email']);
    $this->assertEquals(0, $emails['values'][1]['is_primary']);
    $this->assertEquals('minnie@mouse.org', $emails['values'][1]['email']);
  }

  /**
   * Check that without an email the match is accepted with an employer
   * connection.
   *
   * @throws \Civi\WMFException\WMFException
   * @throws \League\Csv\Exception
   */
  public function testImportSucceedIndividualOneMatchNoEmailEmployerMatch(): void {
    $organization = $this->createTestEntity('Contact', [
      'organization_name' => 'Mickey Mouse Inc',
      'contact_type' => 'Organization',
    ]);
    $minnie = $this->createTestEntity('Contact', [
      'first_name' => 'Minnie',
      'last_name' => 'Mouse',
      'contact_type' => 'Individual',
      'email_primary.email' => 'minnie@mouse.org',
    ]);

    $betterMinnie = $this->createTestEntity('Contact', [
      'first_name' => 'Minnie',
      'last_name' => 'Mouse',
      'contact_type' => 'Individual',
      'email_primary.email' => 'minnie@mouse.org',
      'employer_id' => $organization['id'],
    ]);

    $importer = new BenevityFile($this->getCsvDirectory() . "benevity_mice_no_email.csv", ['date' => ['year' => 2019, 'month' => 9, 'day' => 12]]);
    $importer->import();
    $messages = $importer->getMessages();
    $this->assertEquals('All rows were imported', $messages['Result']);

    $contributions = $this->callAPISuccess('Contribution', 'get', ['contact_id' => $minnie['id']]);
    $this->assertEquals(0, $contributions['count']);
    $contributions = $this->callAPISuccess('Contribution', 'get', [
      'contact_id' => $betterMinnie['id'],
      'sequential' => 1,
      'version' => 4,
      'return' => [
        'contribution_extra.no_thank_you',
        'Gift_Data.Fund',
        'Gift_Data.Campaign',
      ],
    ])['values'];
    $this->assertCount(2, $contributions);
    $contribution1 = $contributions[0];
    $this->assertEquals(1, $contribution1['contribution_extra.no_thank_you'], 'No thank you should be set');
    $this->assertEquals('Individual Gift', $contribution1['Gift_Data.Campaign']);
    $this->assertEquals('Unrestricted - General', $contribution1['Gift_Data.Fund']);

    $contribution2 = $contributions[1];
    $this->assertEquals(1, $contribution2['contribution_extra.no_thank_you']);
    // Bluesnap is matched to individual gift.
    $this->assertEquals('Individual Gift', $contribution2['Gift_Data.Campaign']);
    $this->assertEquals('Unrestricted - General', $contribution2['Gift_Data.Fund']);

    $organizationContributions = $this->callAPISuccess('Contribution', 'get', [
      'contact_id' => $organization['id'],
      'sequential' => 1,
      'version' => 4,
      'return' => [
        'contribution_extra.no_thank_you',
        'Gift_Data.Fund',
        'Gift_Data.Campaign',
      ],
    ]);
    foreach ($organizationContributions['values'] as $contribution) {
      $this->assertEquals(1, $contribution['contribution_extra.no_thank_you']);
      $this->assertEquals('Unrestricted - General', $contribution['Gift_Data.Fund']);
      $this->assertEquals('Matching Gift', $contribution['Gift_Data.Campaign']);
    }
  }

  /**
   * Check that without an email & no employer connection a match is not made.
   *
   * If there is no employer connection a new contact should be created.
   *
   * @throws \League\Csv\Exception
   * @throws \Civi\WMFException\WMFException
   */
  public function testImportSucceedIndividualOneMatchNoEmailNoEmployerMatch(): void {
    $organization = $this->createTestEntity('Contact', [
      'organization_name' => 'Mickey Mouse Inc',
      'contact_type' => 'Organization',
    ]);
    $minnie = $this->createTestEntity('Contact', [
      'first_name' => 'Minnie',
      'last_name' => 'Mouse',
      'contact_type' => 'Individual',
      'email_primary.email' => 'minnie@mouse.org',
    ]);

    $importer = new BenevityFile($this->getCsvDirectory() . "benevity_mice_no_email.csv", ['date' => ['year' => 2019, 'month' => 9, 'day' => 12]]);
    $importer->import();
    $messages = $importer->getMessages();
    $this->assertEquals('All rows were imported', $messages['Result']);

    $contributions = $this->callAPISuccess('Contribution', 'get', ['contact_id' => $minnie['id']]);
    $this->assertEquals(0, $contributions['count']);
    $relationships = $this->callAPISuccess('Relationship', 'get', ['contact_id_b' => $organization['id']]);
    $this->assertEquals(1, $relationships['count']);
    $individualID = $relationships['values'][$relationships['id']]['contact_id_a'];
    $contributions = $this->callAPISuccess('Contribution', 'get', ['contact_id' => $individualID]);
    // Note that both imported records have been matched up. They are both legit matches based on our rules.
    // It feels weird because one has less data than the other. But single name contacts should be
    // very rare & single name contacts that match a different double-name contact in the same
    // org seems 'beyond-edge'
    $this->assertEquals(2, $contributions['count']);
  }

  /**
   * Test that rollback works.
   *
   * If part of the transaction fails it should be fully rolled back. Here we
   * ensure the second transaction fails and the created individual and created
   * contribution have also been rolled back.
   */
  public function testImportDuplicateFullRollback(): void {
    $organization = $this->createTestEntity('Contact', [
      'organization_name' => 'Mickey Mouse Inc',
      'contact_type' => 'Organization',
    ]);
    // This will clash with the second transaction causing it to fail.

    $this->callAPISuccess('Contribution', 'create', [
      'trxn_id' => 'BENEVITY TRXN-SQUEAK_MATCHED',
      'financial_type_id' => 'Engage',
      'total_amount' => 5,
      'contact_id' => $organization['id'],
    ]);
    $messages = $this->importBenevityFile();
    $this->assertEquals('0 out of 5 rows were imported.', $messages['Result']);
    $contribution = $this->callAPISuccess('Contribution', 'get', ['trxn_id' => 'BENEVITY TRXN-SQUEAK']);
    $this->assertEquals(0, $contribution['count'], 'This contribution should have been rolled back');
    $minnie = $this->callAPISuccess('Contact', 'get', [
      'first_name' => 'Minnie',
      'last_name' => 'Mouse',
    ]);
    $this->assertEquals(0, $minnie['count'], 'This contact should have been rolled back');
  }

  /**
   * Import the same file twice, checking all are seen as duplicates on round 2.
   *
   * We check this by making sure none are reported as errors.
   *
   * @throws \League\Csv\Exception
   * @throws \Civi\WMFException\WMFException
   */
  public function testDuplicateDetection(): void {
    $this->createAllOrganizations();

    $importer = new BenevityFile($this->getCsvDirectory() . "benevity.csv", ['date' => ['year' => 2019, 'month' => 9, 'day' => 12]]);
    $importer->import();

    $messages = $this->importBenevityFile();
    $this->assertEquals('0 out of 5 rows were imported.', $messages['Result']);
    $this->assertNotTrue(isset($messages['Error']));
    $this->assertEquals(5, substr($messages['Duplicate'], 0, 1));
  }

  /**
   * Import a transaction where half the transaction has already been imported.
   *
   * This should throw an error rather be treated as a valid duplicate.
   */
  public function testDuplicateDetectionInvalidState(): void {
    [$mouseOrg] = $this->createAllOrganizations();

    $existing = $this->createTestEntity('Contribution', [
      'trxn_id' => 'BENEVITY TRXN-SQUEAK_MATCHED',
      'financial_type_id:name' => 'Engage',
      'total_amount' => 5,
      'source' => 'USD 5.00',
      'contact_id' => $mouseOrg['id'],
    ]);

    $messages = $this->importBenevityFile();
    $this->assertEquals('4 out of 5 rows were imported.', $messages['Result']);
    $this->assertEquals(1, substr($messages['Error'], 0, 1));

    // Update existing dupe to match the donor transaction instead of the matching one.
    // This should also result in an error as 1 out of 2 transactions for the row is imported.
    $this->callAPISuccess('Contribution', 'create', [
      'trxn_id' => 'BENEVITY TRXN-SQUEAK',
      'financial_type_id' => 'Engage',
      'total_amount' => 5,
      'contact_id' => $mouseOrg['id'],
      'id' => $existing['id'],
    ]);

    $messages = $this->importBenevityFile();
    $this->assertEquals('0 out of 5 rows were imported.', $messages['Result']);
    $this->assertEquals(4, substr($messages['Duplicate'], 0, 1));
    $this->assertEquals(1, substr($messages['Error'], 0, 1));

    // Create a second so they both match - should be a duplicate instead of an error now.
    $this->callAPISuccess('Contribution', 'create', [
      'trxn_id' => 'BENEVITY TRXN-SQUEAK_MATCHED',
      'financial_type_id' => 'Engage',
      'total_amount' => 5,
      'contact_id' => $mouseOrg['id'],
    ]);

    $messages = $this->importBenevityFile();
    $this->assertEquals('0 out of 5 rows were imported.', $messages['Result']);
    $this->assertEquals(5, substr($messages['Duplicate'], 0, 1));
    $this->assertNotTrue(isset($messages['Error']));
  }

  /**
   * Test import succeeds if there is exactly one organization with the name as
   * a nick name.
   *
   * If this is the case then the presence of other organizations with that
   * name as a name should not be a problem.
   */
  public function testImportSucceedOrganizationDisambiguatedBySingleNickName(): void {
    $this->createTestEntity('Contact', [
      'organization_name' => 'Donald Duck Inc',
      'contact_type' => 'Organization',
    ]);
    $theRealDuck = $this->createTestEntity('Contact', [
      'organization_name' => 'Donald Duck',
      'nick_name' => 'Donald Duck Inc',
      'contact_type' => 'Organization',
    ]);
    $this->createTestEntity('Contact', [
      'organization_name' => 'Donald Duck Inc',
      'contact_type' => 'Organization',
    ]);

    $messages = $this->importBenevityFile();
    $this->assertEquals('1 out of 5 rows were imported.', $messages['Result']);
    $contribution = $this->callAPISuccessGetSingle('Contribution', ['trxn_id' => 'BENEVITY TRXN-QUACK']);
    $this->assertEquals(1200, $contribution['total_amount']);

    $address = $this->callAPISuccess('Address', 'get', [
      'contact_id' => $contribution['contact_id'],
      'sequential' => TRUE,
    ]);
    $this->assertEquals('2 Quacker Road', $address['values'][0]['street_address']);
    $this->assertEquals('Duckville', $address['values'][0]['city']);
    $this->assertEquals(90210, $address['values'][0]['postal_code']);

    $orgContributions = $this->callAPISuccess('Contribution', 'get', ['contact_id' => $theRealDuck['id']]);
    // The first row has no matching contribution.
    $this->assertEquals(0, $orgContributions['count']);
  }

  /**
   * Test a successful import run.
   *
   *  - Test migrated to new imports.
   */
  public function testImportSucceedAll(): void {
    [$mouseOrg] = $this->createAllOrganizations();

    $this->createTestEntity('Contact', [
      'first_name' => 'Minnie',
      'last_name' => 'Mouse',
      'contact_type' => 'Individual',
      'email_primary.email' => 'minnie@mouse.org',
      'employer_id' => $mouseOrg['id'],
    ]);

    $messages = $this->importBenevityFile();
    $this->assertEquals('All rows were imported', $messages['Result']);

    $contribution = $this->callAPISuccessGetSingle('Contribution', ['trxn_id' => 'BENEVITY trxn-QUACK']);
    $this->assertEquals(11, $contribution['fee_amount']);
    $this->assertEquals(1189, $contribution['net_amount']);

    $contribution = $this->callAPISuccessGetSingle('Contribution', ['trxn_id' => 'BENEVITY trxn-WOOF']);
    $this->assertEquals(22, $contribution['total_amount']);
    $this->assertEquals(20.41, $contribution['net_amount']);
    $this->assertEquals(1.59, $contribution['fee_amount']);

    // Our dog has very little details, a new contact will have been created for Pluto.
    // It should have an address & a relationship & be soft-credited.
    $dogContact = $this->callAPISuccessGetSingle('Contact', ['id' => $contribution['contact_id']]);
    $dogContributions = $this->callAPISuccess('Contribution', 'get', ['contact_id' => $dogContact['id']]);
    $this->assertEquals(1, $dogContributions['count']);
    $this->assertArrayNotHasKey('soft_credit', $dogContributions['values'][$dogContributions['id']]);
    $dogHouse = $this->callAPISuccess('Address', 'get', [
      'contact_id' => $dogContact['id'],
      'sequential' => 1,
    ]);
    $this->assertEquals(1, $dogHouse['count']);
    $relationships = $this->callAPISuccess('Relationship', 'get', ['contact_id_a' => $dogContact['id']]);
    $this->assertEquals(1, $relationships['count']);

    $orgContributions = $this->callAPISuccess('Contribution', 'get', ['trxn_id' => 'BENEVITY TRXN-WOOF_MATCHED']);
    $goofyIncContribution = $orgContributions['values'][$orgContributions['id']];
    // The first row has a matching contribution.
    $this->assertEquals(1, $orgContributions['count']);
    $this->assertEquals(25, $goofyIncContribution['total_amount']);
    $this->assertEquals(0, $goofyIncContribution['fee_amount']);
    $this->assertEquals(25, $goofyIncContribution['net_amount']);
    $this->assertEquals('Goofy Inc', $goofyIncContribution['display_name']);
    $this->assertEquals($dogContact['id'], $goofyIncContribution['soft_credit_to']);

    $contribution = $this->callAPISuccess('Contribution', 'get', ['trxn_id' => 'BENEVITY TRXN-ARF']);
    $this->assertEquals(0, $contribution['count']);

    $orgContributions = $this->callAPISuccess('Contribution', 'get', ['trxn_id' => 'BENEVITY TRXN-ARF_MATCHED']);
    $mcScrougeGift = $orgContributions['values'][$orgContributions['id']];
    $this->assertEquals(1, $orgContributions['count']);
    $this->assertEquals(.5, $mcScrougeGift['total_amount']);
    $this->assertEquals('2019-09-12 00:00:00', $mcScrougeGift['receive_date']);
    $this->assertEquals(.33, $mcScrougeGift['fee_amount']);
    $this->assertEquals(.17, $mcScrougeGift['net_amount']);

    // No address should have been created for the organization.
    $organizationAddress = $this->callAPISuccess('Address', 'get', [
        'contact_id' => $mcScrougeGift['contact_id'],
      ]
    );
    $this->assertEquals(0, $organizationAddress['count']);

    $anonymousContact = $this->callAPISuccessGetSingle('Contact', ['email' => 'fakeemail@wikimedia.org']);
    $this->assertEquals('Anonymous', $anonymousContact['first_name']);
    $this->assertEquals('Anonymous', $anonymousContact['last_name']);
    // Let's not soft credit anonymous.
    $this->assertArrayNotHasKey('soft_credit_to', $orgContributions['values'][$orgContributions['id']]);
    $relationships = $this->callAPISuccess('Relationship', 'get', ['contact_id_a' => $anonymousContact['id']]);
    $this->assertEquals(0, $relationships['count']);

    $mice = $this->callAPISuccess('Contact', 'get', [
      'first_name' => 'Minnie',
      'last_name' => 'Mouse',
    ]);
    $minnie = $mice['values'][$mice['id']];
    $this->assertEquals('2 Cheesy Place', $minnie['street_address']);
    $this->assertEquals('Mickey Mouse Inc', $minnie['current_employer']);
    $relationships = $this->callAPISuccess('Relationship', 'get', ['contact_id_a' => $minnie['id']]);
    $this->assertEquals(1, $relationships['count']);
  }

  /**
   * Test that currency information can be handled as input.
   *
   * - Test not appropriate to transfer to new imports as relates to drupal form.
   */
  public function testImportSucceedCurrencyTransformExists(): void {
    [$mouseOrg, $minnie] = $this->spawnMice();
    $messages = $this->importBenevityFile(['date' => ['year' => 2019, 'month' => 9, 'day' => 12], 'original_currency' => 'EUR', 'original_currency_total' => 4, 'usd_total' => 8]);
    $this->assertEquals('1 out of 5 rows were imported.', $messages['Result']);

    $contribution = $this->callAPISuccessGetSingle('Contribution', ['contact_id' => $minnie['id']]);
    $this->assertEquals(200, $contribution['total_amount']);
    $this->assertEquals('EUR 100.00', $contribution['contribution_source']);

    $contribution = $this->callAPISuccessGetSingle('Contribution', ['contact_id' => $mouseOrg['id']]);
    $this->assertEquals(2000, $contribution['total_amount']);
    $this->assertEquals('EUR 1000.00', $contribution['contribution_source']);
  }

  /**
   * Test that currency information can be handled as input.
   *
   *  - Test not appropriate to transfer to new imports as relates to drupal form.
   */
  public function testImportSucceedCurrencyWithOriginalCurrencyFee(): void {
    $this->setExchangeRates(strtotime('2019-09-12'), ['USD' => 1, 'JPY' => 100]);
    [, $minnie] = $this->spawnMice();
    global $_exchange_rate_cache;
    $messages = $this->importBenevityFile(['date' => ['year' => 2019, 'month' => 9, 'day' => 12], 'original_currency' => 'JPY', 'original_currency_total' => 4000, 'usd_total' => 40], 'benevity.jpy');
    global $user;
    $suffix = $user->uid . '.csv';
    $this->assertEquals('All rows were imported', $messages['Result'], "\n" . print_r(file_get_contents($this->getCsvDirectory() . 'benevity.jpy_errors.' . $suffix), TRUE) . "\n" . print_r($_exchange_rate_cache, TRUE));
    $contribution = $this->callAPISuccessGetSingle('Contribution', ['contact_id' => $minnie['id']]);
    $this->assertEquals((464 + 254) / 100, $contribution['fee_amount']);
  }

  /**
   * @return array
   */
  protected function createAllOrganizations(): array {
    $mouseOrg = $this->createTestEntity('Contact', [
      'organization_name' => 'Mickey Mouse Inc',
      'contact_type' => 'Organization',
    ]);
    $dogOrg = $this->createTestEntity('Contact', [
      'organization_name' => 'Goofy Inc',
      'contact_type' => 'Organization',
    ]);
    $duckOrg = $this->createTestEntity('Contact', [
      'organization_name' => 'Donald Duck Inc',
      'contact_type' => 'Organization',
    ]);
    $stingyOrg = $this->createTestEntity('Contact', [
      'organization_name' => 'Uncle Scrooge Inc',
      'contact_type' => 'Organization',
    ]);
    return [$mouseOrg, $dogOrg, $duckOrg, $stingyOrg];
  }

  /**
   * Do the benevity file import.
   *
   * @param array $additionalFields
   *
   * @param string $csv
   *
   * @return array
   *
   * @noinspection PhpUnhandledExceptionInspection
   * @noinspection PhpDocMissingThrowsInspection
   */
  protected function importBenevityFile(array $additionalFields = ['date' => ['year' => 2019, 'month' => 9, 'day' => 12]], string $csv = 'benevity'): array {
    $importer = new BenevityFile($this->getCsvDirectory() . $csv . '.csv', $additionalFields);
    $importer->import();
    return $importer->getMessages();
  }

  /**
   * @return array
   */
  protected function spawnMice(): array {
    $mouseOrg = $this->createTestEntity('Contact', [
      'organization_name' => 'Mickey Mouse Inc',
      'contact_type' => 'Organization',
    ]);
    $minnie = $this->createTestEntity('Contact', [
      'first_name' => 'Minnie',
      'last_name' => 'Mouse',
      'contact_type' => 'Individual',
      'email_primary.email' => 'minnie@mouse.org',
    ]);
    return [$mouseOrg, $minnie];
  }

}

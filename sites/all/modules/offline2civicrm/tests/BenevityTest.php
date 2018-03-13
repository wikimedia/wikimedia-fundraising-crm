<?php

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

  function setUp() {
    parent::setUp();

    $this->setExchangeRates($this->epochtime, array('USD' => 1, 'BTC' => 3));
    $this->gateway = 'benevity';
    civicrm_initialize();
    CRM_Core_DAO::executeQuery("
      DELETE FROM civicrm_contribution
      WHERE trxn_id LIKE 'BENEVITY%'
    ");
    CRM_Core_DAO::executeQuery("
      DELETE FROM civicrm_contact
      WHERE organization_name IN('Donald Duck Inc', 'Mickey Mouse Inc', 'Goofy Inc', 'Uncle Scrooge Inc') 
      OR nick_name IN('Donald Duck Inc', 'Mickey Mouse Inc', 'Goofy Inc', 'Uncle Scrooge Inc')
      OR first_name = 'Minnie' AND last_name = 'Mouse'
      OR first_name = 'Pluto'
    ");
    $this->ensureAnonymousContactExists();
    \Civi::$statics = array();
    $countries = $this->callAPISuccess('Country', 'get', array('options' => array('limit' => 0)));
    $this->callAPISuccess('Setting', 'create', array('countryLimit' => array_keys($countries['values'])));
    $this->callAPISuccess('Setting', 'create', array('provinceLimit' => array()));

  }

  /**
   * Test that all imports fail if the organization has multiple matches.
   */
  function testImportFailOrganizationContactAmbiguous() {
    $this->callAPISuccess('Contact', 'create', array(
      'organization_name' => 'Donald Duck Inc',
      'contact_type' => 'Organization',
    ));
    $this->callAPISuccess('Contact', 'create', array(
      'organization_name' => 'Donald Duck Inc',
      'contact_type' => 'Organization',
    ));
    $messages = $this->importBenevityFile();
    $this->assertEquals('0 out of 4 rows were imported.', $messages['Result']);
  }

  /**
   * Test that all imports fail if the organization does not pre-exist.
   */
  function testImportFailNoOrganizationContactExists() {
    $messages = $this->importBenevityFile();
    $this->assertEquals('0 out of 4 rows were imported.', $messages['Result']);
  }

  /**
   * Test that import passes for the contact if a single match is found.
   */
  function testImportSucceedOrganizationSingleContactExists() {
    $this->callAPISuccess('Contact', 'create', array(
      'organization_name' => 'Donald Duck Inc',
      'contact_type' => 'Organization',
    ));
    $messages = $this->importBenevityFile();
    $this->assertEquals('1 out of 4 rows were imported.', $messages['Result']);
  }

  /**
   * Test that import passes for the Individual contact if a single match is
   * found.
   */
  function testImportSucceedIndividualSingleContactExists() {
    $thaMouseMeister = $this->callAPISuccess('Contact', 'create', array(
      'organization_name' => 'Mickey Mouse Inc',
      'contact_type' => 'Organization',
    ));
    $minnie = $this->callAPISuccess('Contact', 'create', array(
      'first_name' => 'Minnie',
      'last_name' => 'Mouse',
      'contact_type' => 'Individual',
      'email' => 'minnie@mouse.org',
    ));
    $messages = $this->importBenevityFile();
    $this->assertEquals('1 out of 4 rows were imported.', $messages['Result']);
    $contributions = $this->callAPISuccess('Contribution', 'get', array('contact_id' => $minnie['id']));
    $this->assertEquals(1, $contributions['count']);
    $relationships = $this->callAPISuccess('Relationship', 'get', array(
      'contact_id_a' => $minnie['id'],
      'contact_id_b' => $thaMouseMeister['id'],
    ));
    $this->assertEquals(1, $relationships['count']);
  }

  /**
   * Test that import passes for the Individual contact when no single match is
   * found.
   *
   * In this scenario an email exists so a contact is created. The
   * origanization exists and can be matched, however the individual does not
   * exist & should be created.
   */
  function testImportSucceedIndividualNoExistingMatch() {
    $thaMouseMeister = $this->callAPISuccess('Contact', 'create', array(
      'organization_name' => 'Mickey Mouse Inc',
      'contact_type' => 'Organization',
    ));
    $messages = $this->importBenevityFile();
    $this->assertEquals('1 out of 4 rows were imported.', $messages['Result']);
    $contribution = $this->callAPISuccessGetSingle('Contribution', array('trxn_id' => 'BENEVITY TRXN-SQUEAK'));
    $this->assertEquals('Benevity', $contribution['financial_type']);
    $relationships = $this->callAPISuccess('Relationship', 'get', array(
        'contact_id_a' => $contribution['contact_id'],
        'contact_id_b' => $thaMouseMeister['id'],
      )
    );
    $this->assertEquals(1, $relationships['count']);
    $minnie = $this->callAPISuccessGetSingle('Contact', array(
      'id' => $contribution['contact_id'],
      'return' => 'email',
    ));
    $this->assertEquals('minnie@mouse.org', $minnie['email']);
  }

  /**
   * Test that import works when creating a contact just for the matching gift.
   *
   * In this scenario an email exists so a contact is created. The contact does
   * not make a donation but is soft credited the organisation's donation.
   */
  function testImportSucceedIndividualNoExistingMatchOnlyMatchingGift() {
    $thaMouseMeister = $this->callAPISuccess('Contact', 'create', array(
      'organization_name' => 'Mickey Mouse Inc',
      'contact_type' => 'Organization',
    ));
    $relationships = $this->callAPISuccess('Relationship', 'get', array(
        'contact_id_b' => $thaMouseMeister['id'],
      )
    );
    $this->assertEquals(0, $relationships['count']);

    $importer = new BenevityFile(__DIR__ . "/data/benevity_only_match.csv");
    $importer->import();
    $messages = $importer->getMessages();
    $this->assertEquals('All rows were imported', $messages['Result']);
    $contribution = $this->callAPISuccessGetSingle('Contribution', array('trxn_id' => 'BENEVITY TRXN-SQUEAK_MATCHED'));
    $relationship = $this->callAPISuccessGetSingle('Relationship', array(
        'contact_id_b' => $thaMouseMeister['id'],
      )
    );
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
   */
  function testImportSucceedIndividualSofCreditMatchMatchingGiftNoDonorGift() {
    $thaMouseMeister = $this->callAPISuccess('Contact', 'create', array(
      'organization_name' => 'Mickey Mouse Inc',
      'contact_type' => 'Organization',
    ));
    $minnie = $this->callAPISuccess('Contact', 'create', array(
      'first_name' => 'Minnie',
      'last_name' => 'Mouse',
      'contact_type' => 'Individual',
      'email' => 'minnie@mouse_home.org',
    ));
    // Create a contribution on the organisation, soft credited to Better Minnie.
    $this->callAPISuccess('Contribution', 'create', array(
      'total_amount' => 4,
      'financial_type_id' => 'Donation',
      'soft_credit_to' => $minnie['id'],
      'contact_id' => $thaMouseMeister['id'],
    ));

    $importer = new BenevityFile(__DIR__ . "/data/benevity_only_match.csv");
    $importer->import();
    $messages = $importer->getMessages();
    $this->assertEquals('All rows were imported', $messages['Result']);
    $contribution = $this->callAPISuccessGetSingle('Contribution', array('trxn_id' => 'BENEVITY TRXN-SQUEAK_MATCHED'));
    $relationship = $this->callAPISuccessGetSingle('Relationship', array(
        'contact_id_b' => $thaMouseMeister['id'],
      )
    );
    $this->assertEquals($relationship['contact_id_a'], $contribution['soft_credit_to']);
  }

  /**
   * Test that import resolves ambiguous individuals by choosing based on the
   * employer.
   */
  function testImportSucceedIndividualDismabiguateByEmployer() {
    $organization = $this->callAPISuccess('Contact', 'create', array(
      'organization_name' => 'Mickey Mouse Inc',
      'contact_type' => 'Organization',
    ));
    $minnie = $this->callAPISuccess('Contact', 'create', array(
      'first_name' => 'Minnie',
      'last_name' => 'Mouse',
      'contact_type' => 'Individual',
      'email' => 'minnie@mouse.org',
    ));
    $betterMinnie = $this->callAPISuccess('Contact', 'create', array(
      'first_name' => 'Minnie',
      'last_name' => 'Mouse',
      'contact_type' => 'Individual',
      'email' => 'minnie@mouse.org',
      'employer_id' => $organization['id'],
    ));
    $messages = $this->importBenevityFile();
    $this->assertEquals('1 out of 4 rows were imported.', $messages['Result']);
    $contributions = $this->callAPISuccess('Contribution', 'get', array('contact_id' => $minnie['id']));
    $this->assertEquals(0, $contributions['count']);

    $contributions = $this->callAPISuccess('Contribution', 'get', array('contact_id' => $betterMinnie['id']));
    $this->assertEquals(1, $contributions['count']);
    $relationships = $this->callAPISuccess('Relationship', 'get', array(
      'contact_id_a' => $betterMinnie['id'],
      'contact_id_b' => $organization['id'],
    ));
    $this->assertEquals(1, $relationships['count']);
  }

  /**
   * Test that import resolves ambiguous individuals by choosing based on the
   * employer.
   */
  function testImportSucceedIndividualDisambiguateByEmployerEmailAdded() {
    $organization = $this->callAPISuccess('Contact', 'create', array(
      'organization_name' => 'Mickey Mouse Inc',
      'contact_type' => 'Organization',
    ));
    $minnie = $this->callAPISuccess('Contact', 'create', array(
      'first_name' => 'Minnie',
      'last_name' => 'Mouse',
      'contact_type' => 'Individual',
    ));
    $betterMinnie = $this->callAPISuccess('Contact', 'create', array(
      'first_name' => 'Minnie',
      'last_name' => 'Mouse',
      'contact_type' => 'Individual',
      'employer_id' => $organization['id'],
    ));
    $messages = $this->importBenevityFile();
    $this->assertEquals('1 out of 4 rows were imported.', $messages['Result']);
    $contributions = $this->callAPISuccess('Contribution', 'get', array('contact_id' => $minnie['id']));
    $this->assertEquals(0, $contributions['count']);

    $contributions = $this->callAPISuccess('Contribution', 'get', array('contact_id' => $betterMinnie['id']));
    $this->assertEquals(1, $contributions['count']);
    $relationships = $this->callAPISuccess('Relationship', 'get', array(
      'contact_id_a' => $betterMinnie['id'],
      'contact_id_b' => $organization['id'],
    ));
    $this->assertEquals(1, $relationships['count']);
    $this->callAPISuccessGetSingle('Email', array('email' => 'minnie@mouse.org'));
  }

  /**
   * Test that import creates new contacts when it can't resolve to a single
   * contact.
   */
  function testImportSucceedIndividualTooManyChoicesCantDecideSpamTheDB() {
    $organization = $this->callAPISuccess('Contact', 'create', array(
      'organization_name' => 'Mickey Mouse Inc',
      'contact_type' => 'Organization',
    ));
    $minnie = $this->callAPISuccess('Contact', 'create', array(
      'first_name' => 'Minnie',
      'last_name' => 'Mouse',
      'contact_type' => 'Individual',
      'email' => 'minnie@mouse.org',
    ));
    $doppelgangerMinnie = $this->callAPISuccess('Contact', 'create', array(
      'first_name' => 'Minnie',
      'last_name' => 'Mouse',
      'contact_type' => 'Individual',
      'email' => 'minnie@mouse.org',
    ));
    $importer = new BenevityFile(__DIR__ . "/data/benevity_mice_no_email.csv");
    $importer->import();
    $messages = $importer->getMessages();
    $this->assertEquals('All rows were imported', $messages['Result']);

    // All you Minnie's are not the real Minnie
    $contributions = $this->callAPISuccess('Contribution', 'get', array('contact_id' => $minnie['id']));
    $this->assertEquals(0, $contributions['count']);
    $contributions = $this->callAPISuccess('Contribution', 'get', array('contact_id' => $doppelgangerMinnie['id']));
    $this->assertEquals(0, $contributions['count']);

    // Will the Real Minnie Mouse Please stand up.
    $relationship = $this->callAPISuccessGetSingle('Relationship', array('contact_id_b' => $organization['id']));
    $this->assertNotEquals($minnie['id'], $relationship['contact_id_a']);
    $this->assertNotEquals($doppelgangerMinnie['id'], $relationship['contact_id_a']);

    $contributions = $this->callAPISuccess('Contribution', 'get', array('contact_id' => $relationship['contact_id_a']));
    $this->assertEquals(2, $contributions['count']);
  }

  /**
   * Test that import resolves ambiguous individuals by choosing based on the
   * employer where nick_name match in play.
   */
  function testImportSucceedIndividualDismabiguateByEmployerNickName() {
    $organization = $this->callAPISuccess('Contact', 'create', array(
      'organization_name' => 'Micey',
      'nick_name' => 'Mickey Mouse Inc',
      'contact_type' => 'Organization',
    ));
    $minnie = $this->callAPISuccess('Contact', 'create', array(
      'first_name' => 'Minnie',
      'last_name' => 'Mouse',
      'contact_type' => 'Individual',
      'email' => 'minnie@mouse.org',
    ));
    $betterMinnie = $this->callAPISuccess('Contact', 'create', array(
      'first_name' => 'Minnie',
      'last_name' => 'Mouse',
      'contact_type' => 'Individual',
      'email' => 'minnie@mouse.org',
      'employer_id' => $organization['id'],
    ));
    $messages = $this->importBenevityFile();
    $this->assertEquals('1 out of 4 rows were imported.', $messages['Result']);
    $contributions = $this->callAPISuccess('Contribution', 'get', array('contact_id' => $minnie['id']));
    $this->assertEquals(0, $contributions['count']);

    $contributions = $this->callAPISuccess('Contribution', 'get', array('contact_id' => $betterMinnie['id']));
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
  function testImportSucceedIndividualDismabiguateByPreviousSoftCredit() {
    $organization = $this->callAPISuccess('Contact', 'create', array(
      'organization_name' => 'Mickey Mouse Inc',
      'contact_type' => 'Organization',
    ));
    $minnie = $this->callAPISuccess('Contact', 'create', array(
      'first_name' => 'Minnie',
      'last_name' => 'Mouse',
      'contact_type' => 'Individual',
      'email' => 'minnie@mouse.org',
    ));
    $betterMinnie = $this->callAPISuccess('Contact', 'create', array(
      'first_name' => 'Minnie',
      'last_name' => 'Mouse',
      'contact_type' => 'Individual',
      'email' => 'minnie@mouse.org',
    ));
    // Create a contribution on the organisation, soft credited to Better Minnie.
    $this->callAPISuccess('Contribution', 'create', array(
      'total_amount' => 4,
      'financial_type_id' => 'Donation',
      'soft_credit_to' => $betterMinnie['id'],
      'contact_id' => $organization['id'],
    ));
    $messages = $this->importBenevityFile();
    $this->assertEquals('1 out of 4 rows were imported.', $messages['Result']);
    $contributions = $this->callAPISuccess('Contribution', 'get', array('contact_id' => $minnie['id']));
    $this->assertEquals(0, $contributions['count']);

    $contributions = $this->callAPISuccess('Contribution', 'get', array('contact_id' => $betterMinnie['id']));
    $this->assertEquals(1, $contributions['count']);
    $relationships = $this->callAPISuccess('Relationship', 'get', array(
      'contact_id_a' => $betterMinnie['id'],
      'contact_id_b' => $organization['id'],
    ));
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
  function testImportSucceedIndividualCreateIfAmbiguousPreviousSoftCredit() {
    $organization = $this->callAPISuccess('Contact', 'create', array(
      'organization_name' => 'Mickey Mouse Inc',
      'contact_type' => 'Organization',
    ));
    $minnie = $this->callAPISuccess('Contact', 'create', array(
      'first_name' => 'Minnie',
      'last_name' => 'Mouse',
      'contact_type' => 'Individual',
      'email' => 'minnie@mouse.org',
    ));
    $betterMinnie = $this->callAPISuccess('Contact', 'create', array(
      'first_name' => 'Minnie',
      'last_name' => 'Mouse',
      'contact_type' => 'Individual',
      'email' => 'minnie@mouse.org',
    ));
    foreach (array($minnie, $betterMinnie) as $mouse) {
      // Create a contribution on the organisation, soft credited to each mouse..
      $this->callAPISuccess('Contribution', 'create', array(
        'total_amount' => 4,
        'financial_type_id' => 'Donation',
        'soft_credit_to' => $mouse['id'],
        'contact_id' => $organization['id'],
      ));
    }

    $messages = $this->importBenevityFile();
    $this->assertEquals('1 out of 4 rows were imported.', $messages['Result']);
    $contributions = $this->callAPISuccess('Contribution', 'get', array(
      'contact_id' => array(
        'IN' => array(
          $minnie['id'],
          $betterMinnie['id'],
        ),
      ),
    ));
    $this->assertEquals(0, $contributions['count']);

    $newestMouse = $this->callAPISuccessGetSingle('Contact', array(
      'id' => array('NOT IN' => array($minnie['id'], $betterMinnie['id'])),
      'first_name' => 'Minnie',
      'last_name' => 'Mouse',
    ));
    $contributions = $this->callAPISuccess('Contribution', 'get', array('contact_id' => $newestMouse['id']));
    $this->assertEquals(1, $contributions['count']);
    $relationships = $this->callAPISuccess('Relationship', 'get', array(
      'contact_id_a' => $newestMouse['id'],
      'contact_id_b' => $organization['id'],
    ));
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
  function testImportSucceedIndividualPreferRelationshipOverPreviousSoftCredit() {
    $organization = $this->callAPISuccess('Contact', 'create', array(
      'organization_name' => 'Mickey Mouse Inc',
      'contact_type' => 'Organization',
    ));
    $minnie = $this->callAPISuccess('Contact', 'create', array(
      'first_name' => 'Minnie',
      'last_name' => 'Mouse',
      'contact_type' => 'Individual',
      'email' => 'minnie@mouse.org',
    ));
    $betterMinnie = $this->callAPISuccess('Contact', 'create', array(
      'first_name' => 'Minnie',
      'last_name' => 'Mouse',
      'contact_type' => 'Individual',
      'email' => 'minnie@mouse.org',
      'employer_id' => $organization['id'],
    ));

    // Create a contribution on the organisation, soft credited to each minne.
    $this->callAPISuccess('Contribution', 'create', array(
      'total_amount' => 4,
      'financial_type_id' => 'Donation',
      'soft_credit_to' => $minnie['id'],
      'contact_id' => $organization['id'],
    ));

    // But betterMinnie has a relationship, she wins.

    $messages = $this->importBenevityFile();
    $this->assertEquals('1 out of 4 rows were imported.', $messages['Result']);
    $contributions = $this->callAPISuccess('Contribution', 'get', array('contact_id' => $minnie['id']));
    $this->assertEquals(0, $contributions['count']);

    $this->callAPISuccessGetSingle('Contribution', array('contact_id' => $betterMinnie['id']));
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
  function testImportSucceedIndividualMatchToEmployerDisregardingEmail() {
    $organization = $this->callAPISuccess('Contact', 'create', array(
      'organization_name' => 'Mickey Mouse Inc',
      'contact_type' => 'Organization',
    ));
    $betterMinnie = $this->callAPISuccess('Contact', 'create', array(
      'first_name' => 'Minnie',
      'last_name' => 'Mouse',
      'contact_type' => 'Individual',
      'email' => 'minnie@mouse_home.org',
      'employer_id' => $organization['id'],
    ));
    $messages = $this->importBenevityFile();
    $this->assertEquals('1 out of 4 rows were imported.', $messages['Result']);

    $contributions = $this->callAPISuccess('Contribution', 'get', array('contact_id' => $betterMinnie['id']));
    $this->assertEquals(1, $contributions['count']);
    $relationships = $this->callAPISuccess('Relationship', 'get', array(
      'contact_id_a' => $betterMinnie['id'],
      'contact_id_b' => $organization['id'],
    ));
    $this->assertEquals(1, $relationships['count']);
    $emails = $this->callAPISuccess('Email', 'get', array(
      'contact_id' => $betterMinnie['id'],
      'sequential' => 1,
    ));
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
   */
  function testImportSucceedIndividualOneMatchNoEmailEmployerMatch() {
    $organization = $this->callAPISuccess('Contact', 'create', array(
      'organization_name' => 'Mickey Mouse Inc',
      'contact_type' => 'Organization',
    ));
    $minnie = $this->callAPISuccess('Contact', 'create', array(
      'first_name' => 'Minnie',
      'last_name' => 'Mouse',
      'contact_type' => 'Individual',
      'email' => 'minnie@mouse.org',
    ));

    $betterMinnie = $this->callAPISuccess('Contact', 'create', array(
      'first_name' => 'Minnie',
      'last_name' => 'Mouse',
      'contact_type' => 'Individual',
      'email' => 'minnie@mouse.org',
      'employer_id' => $organization['id'],
    ));

    $importer = new BenevityFile(__DIR__ . "/data/benevity_mice_no_email.csv");
    $importer->import();
    $messages = $importer->getMessages();
    $this->assertEquals('All rows were imported', $messages['Result']);

    $contributions = $this->callAPISuccess('Contribution', 'get', array('contact_id' => $minnie['id']));
    $this->assertEquals(0, $contributions['count']);

    $contributions = $this->callAPISuccess('Contribution', 'get', array(
      'contact_id' => $betterMinnie['id'],
      'sequential' => 1,
      'return' => array(
        wmf_civicrm_get_custom_field_name('no_thank_you'),
        wmf_civicrm_get_custom_field_name('Fund'),
        wmf_civicrm_get_custom_field_name('Campaign'),
      ),
    ));
    $this->assertEquals(2, $contributions['count']);
    $contribution1 = $contributions['values'][0];
    $this->assertEquals(1, $contribution1[wmf_civicrm_get_custom_field_name('no_thank_you')], 'No thank you should be set');
    $this->assertEquals('Community Gift', $contribution1[wmf_civicrm_get_custom_field_name('Campaign')]);
    $this->assertEquals('Unrestricted - General', $contribution1[wmf_civicrm_get_custom_field_name('Fund')]);

    $contribution2 = $contributions['values'][1];
    $this->assertEquals(1, $contribution2[wmf_civicrm_get_custom_field_name('no_thank_you')]);
    // This contribution was over $1000 & hence is a benefactor gift.
    $this->assertEquals('Benefactor Gift', $contribution2[wmf_civicrm_get_custom_field_name('Campaign')]);
    $this->assertEquals('Unrestricted - General', $contribution2[wmf_civicrm_get_custom_field_name('Fund')]);

    $organizationContributions = $this->callAPISuccess('Contribution', 'get', array(
      'contact_id' => $organization['id'],
      'sequential' => 1,
      'return' => array(
        wmf_civicrm_get_custom_field_name('no_thank_you'),
        wmf_civicrm_get_custom_field_name('Fund'),
        wmf_civicrm_get_custom_field_name('Campaign'),
      ),
    ));
    foreach ($organizationContributions['values'] as $contribution) {
      $this->assertEquals(1, $contribution[wmf_civicrm_get_custom_field_name('no_thank_you')]);
      $this->assertEquals('Restricted - Foundation', $contribution[wmf_civicrm_get_custom_field_name('Fund')]);
      $this->assertEquals('Matching Gift', $contribution[wmf_civicrm_get_custom_field_name('Campaign')]);
    }
  }

  /**
   * Check that without an email & no employer connection a match is not made.
   *
   * If there is no employer connection a new contact should be created.
   */
  function testImportSucceedIndividualOneMatchNoEmailNoEmployerMatch() {
    $organization = $this->callAPISuccess('Contact', 'create', array(
      'organization_name' => 'Mickey Mouse Inc',
      'contact_type' => 'Organization',
    ));
    $minnie = $this->callAPISuccess('Contact', 'create', array(
      'first_name' => 'Minnie',
      'last_name' => 'Mouse',
      'contact_type' => 'Individual',
      'email' => 'minnie@mouse.org',
    ));

    $importer = new BenevityFile(__DIR__ . "/data/benevity_mice_no_email.csv");
    $importer->import();
    $messages = $importer->getMessages();
    $this->assertEquals('All rows were imported', $messages['Result']);

    $contributions = $this->callAPISuccess('Contribution', 'get', array('contact_id' => $minnie['id']));
    $this->assertEquals(0, $contributions['count']);
    $relationships = $this->callAPISuccess('Relationship', 'get', array('contact_id_b' => $organization['id']));
    $this->assertEquals(1, $relationships['count']);
    $individualID = $relationships['values'][$relationships['id']]['contact_id_a'];
    $contributions = $this->callAPISuccess('Contribution', 'get', array('contact_id' => $individualID));
    // Note that both importees have been matched up. They are both legit matches based on our rules.
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
  function testImportDuplicateFullRollback() {
    $organization = $this->callAPISuccess('Contact', 'create', array(
      'organization_name' => 'Mickey Mouse Inc',
      'contact_type' => 'Organization',
    ));
    // This will clash with the second transaction causing it to fail.

    $this->callAPISuccess('Contribution', 'create', array(
      'trxn_id' => 'BENEVITY TRXN-SQUEAK_MATCHED',
      'financial_type_id' => 'Engage',
      'total_amount' => 5,
      'contact_id' => $organization['id'],
    ));
    $messages = $this->importBenevityFile();
    $this->assertEquals('0 out of 4 rows were imported.', $messages['Result']);
    $contribution = $this->callAPISuccess('Contribution', 'get', array('trxn_id' => 'BENEVITY TRXN-SQUEAK'));
    $this->assertEquals(0, $contribution['count'], 'This contribution should have been rolled back');
    $minnie = $this->callAPISuccess('Contact', 'get', array(
      'first_name' => 'Minnie',
      'last_name' => 'Mouse',
    ));
    $this->assertEquals(0, $minnie['count'], 'This contact should have been rolled back');
  }

  /**
   * Import the same file twice, checking all are seen as duplicates on round 2.
   *
   * We check this by making sure none are reported as errors.
   */
  function testDuplicateDetection() {
    $this->createAllOrgs();

    $importer = new BenevityFile(__DIR__ . "/data/benevity.csv");
    $importer->import();

    $messages = $this->importBenevityFile();
    $this->assertEquals('0 out of 4 rows were imported.', $messages['Result']);
    $this->assertTrue(!isset($messages['Error']));
    $this->assertEquals(4, substr($messages['Duplicate'], 0,1));
  }


  /**
   * Import a transaction where half the transaction has already been imported.
   *
   * This should throw an error rather be treated as a valid duplicate.
   */
  function testDuplicateDetectionInvalidState() {
    list ($mouseOrg) =$this->createAllOrgs();

    $existing = $this->callAPISuccess('Contribution', 'create', array(
      'trxn_id' => 'BENEVITY TRXN-SQUEAK_MATCHED',
      'financial_type_id' => 'Engage',
      'total_amount' => 5,
      'contact_id' => $mouseOrg['id'],
    ));


    $messages = $this->importBenevityFile();
    $this->assertEquals('3 out of 4 rows were imported.', $messages['Result']);
    $this->assertEquals(1, substr($messages['Error'], 0,1));

    // Update existing dupe to match the donor transaction instead of the matching one.
    // This should also result in an error as 1 out of 2 transactions for the row is imported.
    $this->callAPISuccess('Contribution', 'create', array(
      'trxn_id' => 'BENEVITY TRXN-SQUEAK',
      'financial_type_id' => 'Engage',
      'total_amount' => 5,
      'contact_id' => $mouseOrg['id'],
      'id' => $existing['id'],
    ));

    $messages = $this->importBenevityFile();
    $this->assertEquals('0 out of 4 rows were imported.', $messages['Result']);
    $this->assertEquals(3, substr($messages['Duplicate'], 0,1));
    $this->assertEquals(1, substr($messages['Error'], 0,1));

    // Create a second so they both match - should be a duplicate instead of an error now.
    $this->callAPISuccess('Contribution', 'create', array(
      'trxn_id' => 'BENEVITY TRXN-SQUEAK_MATCHED',
      'financial_type_id' => 'Engage',
      'total_amount' => 5,
      'contact_id' => $mouseOrg['id'],
    ));

    $messages = $this->importBenevityFile();
    $this->assertEquals('0 out of 4 rows were imported.', $messages['Result']);
    $this->assertEquals(4, substr($messages['Duplicate'], 0,1));
    $this->assertTrue(!isset($messages['Error']));

  }
  /**
   * Test import succeeds if there is exactly one organization with the name as
   * a nick name.
   *
   * If this is the case then the presence of other organizations with that
   * name as a name should not be a problem.
   */
  function testImportSucceedOrganizationDisambiguatedBySingleNickName() {
    $this->callAPISuccess('Contact', 'create', array(
      'organization_name' => 'Donald Duck Inc',
      'contact_type' => 'Organization',
    ));
    $theRealDuck = $this->callAPISuccess('Contact', 'create', array(
      'organization_name' => 'Donald Duck',
      'nick_name' => 'Donald Duck Inc',
      'contact_type' => 'Organization',
    ));
    $this->callAPISuccess('Contact', 'create', array(
      'organization_name' => 'Donald Duck Inc',
      'contact_type' => 'Organization',
    ));

    $messages = $this->importBenevityFile();
    $this->assertEquals('1 out of 4 rows were imported.', $messages['Result']);
    $contribution = $this->callAPISuccessGetSingle('Contribution', array('trxn_id' => 'BENEVITY TRXN-QUACK'));
    $this->assertEquals(1200, $contribution['total_amount']);

    $address = $this->callAPISuccess('Address', 'get', array(
      'contact_id' => $contribution['contact_id'],
      'sequential' => TRUE,
    ));
    $this->assertEquals('2 Quacker Road', $address['values'][0]['street_address']);
    $this->assertEquals('Duckville', $address['values'][0]['city']);
    $this->assertEquals(90210, $address['values'][0]['postal_code']);

    $orgContributions = $this->callAPISuccess('Contribution', 'get', array('contact_id' => $theRealDuck['id']));
    // The first row has no matching contribution.
    $this->assertEquals(0, $orgContributions['count']);

  }

  /**
   * Test a successful import run.
   */
  function testImportSucceedAll() {
    list($mouseOrg) = $this->createAllOrgs();

    $this->callAPISuccess('Contact', 'create', array(
      'first_name' => 'Minnie',
      'last_name' => 'Mouse',
      'contact_type' => 'Individual',
      'email' => 'minnie@mouse.org',
      'employer_id' => $mouseOrg['id'],
    ));

    $messages = $this->importBenevityFile();
    $this->assertEquals('All rows were imported', $messages['Result']);

    $contribution = $this->callAPISuccessGetSingle('Contribution', array('trxn_id' => 'BENEVITY trxn-WOOF'));
    $this->assertEquals(22, $contribution['total_amount']);
    $this->assertEquals(22, $contribution['net_amount']);

    // Our dog has very little details, a new contact will have been created for Pluto.
    // It should have an address & a relationship & be soft-credited.
    $dogContact = $this->callAPISuccessGetSingle('Contact', array('id' => $contribution['contact_id']));
    $dogContributions = $this->callAPISuccess('Contribution', 'get', array('contact_id' => $dogContact['id']));
    $this->assertEquals(1, $dogContributions['count']);
    $this->assertTrue(empty($dogContributions['values'][$dogContributions['id']]['soft_credit']));
    $dogHouse = $this->callAPISuccess('Address', 'get', array(
      'contact_id' => $dogContact['id'],
      'sequential' => 1,
    ));
    $this->assertEquals(1, $dogHouse['count']);
    $relationships = $this->callAPISuccess('Relationship', 'get', array('contact_id_a' => $dogContact['id']));
    $this->assertEquals(1, $relationships['count']);

    $orgContributions = $this->callAPISuccess('Contribution', 'get', array('trxn_id' => 'BENEVITY TRXN-WOOF_MATCHED'));
    // The first row has a matching contribution.
    $this->assertEquals(1, $orgContributions['count']);
    $this->assertEquals(25, $orgContributions['values'][$orgContributions['id']]['total_amount']);
    $this->assertEquals(25, $orgContributions['values'][$orgContributions['id']]['net_amount']);
    $this->assertEquals('Goofy Inc', $orgContributions['values'][$orgContributions['id']]['display_name']);
    $this->assertEquals($dogContact['id'], $orgContributions['values'][$orgContributions['id']]['soft_credit_to']);

    $contribution = $this->callAPISuccess('Contribution', 'get', array('trxn_id' => 'BENEVITY TRXN-AARF'));
    $this->assertEquals(0, $contribution['count']);

    $orgContributions = $this->callAPISuccess('Contribution', 'get', array('trxn_id' => 'BENEVITY TRXN-AARF_MATCHED'));
    $this->assertEquals(1, $orgContributions['count']);
    $this->assertEquals(.5, $orgContributions['values'][$orgContributions['id']]['total_amount']);

    // No address should have been created for the organization.
    $organizationAddress = $this->callAPISuccess('Address', 'get', array(
        'contact_id' => $orgContributions['values'][$orgContributions['id']]['contact_id'],
      )
    );
    $this->assertEquals(0, $organizationAddress['count']);

    $anonymousContact = $this->callAPISuccessGetSingle('Contact', array('email' => 'fakeemail@wikimedia.org'));
    $this->assertEquals('Anonymous', $anonymousContact['first_name']);
    $this->assertEquals('Anonymous', $anonymousContact['last_name']);
    // Let's not soft credit anonymouse.
    $this->assertTrue(empty($orgContributions['values'][$orgContributions['id']]['soft_credit_to']));
    $relationships = $this->callAPISuccess('Relationship', 'get', array('contact_id_a' => $anonymousContact['id']));
    $this->assertEquals(0, $relationships['count']);

    $mice = $this->callAPISuccess('Contact', 'get', array(
      'first_name' => 'Minnie',
      'last_name' => 'Mouse',
    ));
    $minnie = $mice['values'][$mice['id']];
    $this->assertEquals('2 Cheesey Place', $minnie['street_address']);
    $this->assertEquals('Mickey Mouse Inc', $minnie['current_employer']);
    $relationships = $this->callAPISuccess('Relationship', 'get', array('contact_id_a' => $minnie['id']));
    $this->assertEquals(1, $relationships['count']);
  }

  /**
   * Test that currency information can be handled as input.
   */
  function testImportSucceedCurrencyTransformExists() {
    $mouseOrg = $this->callAPISuccess('Contact', 'create', array(
      'organization_name' => 'Mickey Mouse Inc',
      'contact_type' => 'Organization',
    ));
    $minnie = $this->callAPISuccess('Contact', 'create', array(
      'first_name' => 'Minnie',
      'last_name' => 'Mouse',
      'contact_type' => 'Individual',
      'email' => 'minnie@mouse.org',
    ));
    $messages = $this->importBenevityFile(array('original_currency' => 'EUR', 'original_currency_total' => 4, 'usd_total' => 8));
    $this->assertEquals('1 out of 4 rows were imported.', $messages['Result']);

    $contribution = $this->callAPISuccessGetSingle('Contribution', array('contact_id' => $minnie['id']));
    $this->assertEquals(200, $contribution['total_amount']);
    $this->assertEquals(200, $contribution['total_amount']);
    $this->assertEquals('EUR 100', $contribution['contribution_source']);

    $contribution = $this->callAPISuccessGetSingle('Contribution', array('contact_id' => $mouseOrg['id']));
    $this->assertEquals(200, $contribution['total_amount']);
    $this->assertEquals(200, $contribution['total_amount']);
    $this->assertEquals('EUR 100', $contribution['contribution_source']);
  }

  /**
   * @return array|int
   */
  protected function createAllOrgs() {
    $mouseOrg = $this->callAPISuccess('Contact', 'create', array(
      'organization_name' => 'Mickey Mouse Inc',
      'contact_type' => 'Organization',
    ));
    $dogOrg = $this->callAPISuccess('Contact', 'create', array(
      'organization_name' => 'Goofy Inc',
      'contact_type' => 'Organization',
    ));
    $duckOrg = $this->callAPISuccess('Contact', 'create', array(
      'organization_name' => 'Donald Duck Inc',
      'contact_type' => 'Organization',
    ));
    $stingyOrg = $this->callAPISuccess('Contact', 'create', array(
      'organization_name' => 'Uncle Scrooge Inc',
      'contact_type' => 'Organization',
    ));
    return array($mouseOrg, $dogOrg, $duckOrg, $stingyOrg);
  }

  /**
   * Do the benevity file import.
   *
   * @param array $additionalFields
   *
   * @return array
   */
  protected function importBenevityFile($additionalFields = array()) {
    $importer = new BenevityFile(__DIR__ . "/data/benevity.csv", $additionalFields);
    $importer->import();
    return $importer->getMessages();
  }

}

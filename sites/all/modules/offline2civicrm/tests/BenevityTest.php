<?php

/**
 * @group Import
 * @group Offline2Civicrm
 *
 * Refer to this comment in Phab for rules about when contacts are created. These are
 * the rules the tests are working to (and both should be updated to reflect rule changes).
 * https://phabricator.wikimedia.org/T115044#3012232
 */
class BenevityTest extends BaseChecksFileTest {
  protected $epochtime;

  function setUp() {
    parent::setUp();

    $this->epochtime = wmf_common_date_parse_string('2016-09-15');
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
    $countries = $this->callAPISuccess('Country', 'get', array());
    $this->callAPISuccess('Setting', 'create', array('countryLimit' => array_keys($countries['values'])));

  }

  /**
   * Make sure we have the anonymous contact - like the live DB.
   */
  protected function ensureAnonymousContactExists() {
    $anonymousParams = array(
      'first_name' => 'Anonymous',
      'last_name' => 'Anonymous',
      'email' => 'fakeemail@wikimedia.org',
      'contact_type' => 'Individual',
    );
    $contacts = $this->callAPISuccess('Contact', 'get', $anonymousParams);
    if ($contacts['count'] == 0) {
      $this->callAPISuccess('Contact', 'create', $anonymousParams);
    }
    $contacts = $this->callAPISuccess('Contact', 'get', $anonymousParams);
    $this->assertEquals(1, $contacts['count']);
  }

  /**
   * Test that all imports fail if the organization has multiple matches.
   */
  function testImportFailOrganizationContactAmbiguous() {
    $this->callAPISuccess('Contact', 'create', array('organization_name' => 'Donald Duck Inc', 'contact_type' => 'Organization'));
    $this->callAPISuccess('Contact', 'create', array('organization_name' => 'Donald Duck Inc', 'contact_type' => 'Organization'));
    $importer = new BenevityFile( __DIR__ . "/data/benevity.csv" );
    $importer->import();
    $messages = $importer->getMessages();
    $this->assertEquals('0 out of 4 rows were imported.', $messages['Result']);
  }

  /**
   * Test that all imports fail if the organization does not pre-exist.
   */
  function testImportFailNoOrganizationContactExists() {
    $importer = new BenevityFile( __DIR__ . "/data/benevity.csv" );
    $importer->import();
    $messages = $importer->getMessages();
    $this->assertEquals('0 out of 4 rows were imported.', $messages['Result']);
  }

  /**
   * Test that import passes for the contact if a single match is found.
   */
  function testImportSucceedOrganizationSingleContactExists() {
    $this->callAPISuccess('Contact', 'create', array('organization_name' => 'Donald Duck Inc', 'contact_type' => 'Organization'));
    $importer = new BenevityFile( __DIR__ . "/data/benevity.csv" );
    $importer->import();
    $messages = $importer->getMessages();
    $this->assertEquals('1 out of 4 rows were imported.', $messages['Result']);
  }

  /**
   * Test that import passes for the Individual contact if a single match is found.
   */
  function testImportSucceedIndividualSingleContactExists() {
    $thaMouseMeister = $this->callAPISuccess('Contact', 'create', array('organization_name' => 'Mickey Mouse Inc', 'contact_type' => 'Organization'));
    $minnie = $this->callAPISuccess('Contact', 'create', array(
      'first_name' => 'Minnie', 'last_name' => 'Mouse', 'contact_type' => 'Individual', 'email' => 'minnie@mouse.org',
    ));
    $importer = new BenevityFile( __DIR__ . "/data/benevity.csv" );
    $importer->import();
    $messages = $importer->getMessages();
    $this->assertEquals('1 out of 4 rows were imported.', $messages['Result']);
    $contributions = $this->callAPISuccess('Contribution', 'get', array('contact_id' => $minnie['id']));
    $this->assertEquals(1, $contributions['count']);
    $relationships = $this->callAPISuccess('Relationship', 'get', array('contact_id_a' => $minnie['id'], 'contact_id_b' => $thaMouseMeister['id']));
    $this->assertEquals(1, $relationships['count']);
  }

  /**
   * Test that import passes for the Individual contact when no single match is found.
   *
   * In this scenario an email exists so a contact is created. The origanization exists and can be
   * matched, however the individual does not exist.  Per rules a contact will be created if there is an
   * email but no existing match.
   */
  function testImportSucceedIndividualNoExistingMatch() {
    $thaMouseMeister = $this->callAPISuccess('Contact', 'create', array('organization_name' => 'Mickey Mouse Inc', 'contact_type' => 'Organization'));
    $importer = new BenevityFile( __DIR__ . "/data/benevity.csv" );
    $importer->import();
    $messages = $importer->getMessages();
    $this->assertEquals('1 out of 4 rows were imported.', $messages['Result']);
    $contribution = $this->callAPISuccessGetSingle('Contribution', array('trxn_id' => 'BENEVITY TRXN-SQUEAK'));
    $this->assertEquals('Benevity', $contribution['financial_type']);
    $relationships = $this->callAPISuccess('Relationship', 'get', array(
      'contact_id_a' => $contribution['contact_id'],
      'contact_id_b' => $thaMouseMeister['id'])
    );
    $this->assertEquals(1, $relationships['count']);
  }

  /**
   * Test that import works when creating a contact just for the matching gift.
   *
   * In this scenario an email exists so a contact is created. The contact does
   * not make a donation but is soft credited the organisation's donation.
   */
  function testImportSucceedIndividualNoExistingMatchOnlyMatchingGift() {
    $thaMouseMeister = $this->callAPISuccess('Contact', 'create', array('organization_name' => 'Mickey Mouse Inc', 'contact_type' => 'Organization'));
    $importer = new BenevityFile( __DIR__ . "/data/benevity_only_match.csv" );
    $importer->import();
    $messages = $importer->getMessages();
    $this->assertEquals('All rows were imported', $messages['Result']);
    $contribution = $this->callAPISuccessGetSingle('Contribution', array('trxn_id' => 'BENEVITY TRXN-SQUEAK_MATCHED'));
    $relationship = $this->callAPISuccessGetSingle('Relationship', array(
        'contact_id_b' => $thaMouseMeister['id'])
    );
    $this->assertEquals( $relationship['contact_id_a'], $contribution['soft_credit_to']);
  }

  /**
   * Test that import resolves ambiguous individuals by choosing based on the employer.
   */
  function testImportSucceedIndividualDismabiguateByEmployer() {
    $organization = $this->callAPISuccess('Contact', 'create', array('organization_name' => 'Mickey Mouse Inc', 'contact_type' => 'Organization'));
    $minnie = $this->callAPISuccess('Contact', 'create', array(
      'first_name' => 'Minnie', 'last_name' => 'Mouse', 'contact_type' => 'Individual', 'email' => 'minnie@mouse.org',
    ));
    $betterMinnie = $this->callAPISuccess('Contact', 'create', array(
      'first_name' => 'Minnie', 'last_name' => 'Mouse', 'contact_type' => 'Individual', 'email' => 'minnie@mouse.org', 'employer_id' => $organization['id'],
    ));
    $importer = new BenevityFile( __DIR__ . "/data/benevity.csv" );
    $importer->import();
    $messages = $importer->getMessages();
    $this->assertEquals('1 out of 4 rows were imported.', $messages['Result']);
    $contributions = $this->callAPISuccess('Contribution', 'get', array('contact_id' => $minnie['id']));
    $this->assertEquals(0, $contributions['count']);

    $contributions = $this->callAPISuccess('Contribution', 'get', array('contact_id' => $betterMinnie['id']));
    $this->assertEquals(1, $contributions['count']);
    $relationships = $this->callAPISuccess('Relationship', 'get', array('contact_id_a' => $betterMinnie['id'], 'contact_id_b' => $organization['id']));
    $this->assertEquals(1, $relationships['count']);
  }

  /**
   * Test that import resolves ambiguous individuals by choosing based on the employer where nick_name match in play.
   */
  function testImportSucceedIndividualDismabiguateByEmployerNickName() {
    $organization = $this->callAPISuccess('Contact', 'create', array('organization_name' => 'Micey', 'nick_name' => 'Mickey Mouse Inc', 'contact_type' => 'Organization'));
    $minnie = $this->callAPISuccess('Contact', 'create', array(
      'first_name' => 'Minnie', 'last_name' => 'Mouse', 'contact_type' => 'Individual', 'email' => 'minnie@mouse.org',
    ));
    $betterMinnie = $this->callAPISuccess('Contact', 'create', array(
      'first_name' => 'Minnie', 'last_name' => 'Mouse', 'contact_type' => 'Individual', 'email' => 'minnie@mouse.org', 'employer_id' => $organization['id'],
    ));
    $importer = new BenevityFile( __DIR__ . "/data/benevity.csv" );
    $importer->import();
    $messages = $importer->getMessages();
    $this->assertEquals('1 out of 4 rows were imported.', $messages['Result']);
    $contributions = $this->callAPISuccess('Contribution', 'get', array('contact_id' => $minnie['id']));
    $this->assertEquals(0, $contributions['count']);

    $contributions = $this->callAPISuccess('Contribution', 'get', array('contact_id' => $betterMinnie['id']));
    $this->assertEquals(1, $contributions['count']);
  }

  /**
   * Test that import resolves ambiguous individuals based on previous soft credit history.
   *
   * If an organisation has previously soft credited an individual we consider that
   * to be equivalent to an employer relationship having been formed.
   *
   * Probably longer term the employment relationships will exist and this will be redundant.
   */
  function testImportSucceedIndividualDismabiguateByPreviousSoftCredit() {
    $organization = $this->callAPISuccess('Contact', 'create', array('organization_name' => 'Mickey Mouse Inc', 'contact_type' => 'Organization'));
    $minnie = $this->callAPISuccess('Contact', 'create', array(
      'first_name' => 'Minnie', 'last_name' => 'Mouse', 'contact_type' => 'Individual', 'email' => 'minnie@mouse.org',
    ));
    $betterMinnie = $this->callAPISuccess('Contact', 'create', array(
      'first_name' => 'Minnie', 'last_name' => 'Mouse', 'contact_type' => 'Individual', 'email' => 'minnie@mouse.org',
    ));
    // Create a contribution on the organisation, soft credited to Better Minnie.
    $this->callAPISuccess('Contribution', 'create', array(
      'total_amount' => 4,
      'financial_type_id' => 'Donation',
      'soft_credit_to' => $betterMinnie['id'],
      'contact_id' => $organization['id'],
    ));
    $importer = new BenevityFile( __DIR__ . "/data/benevity.csv" );
    $importer->import();
    $messages = $importer->getMessages();
    $this->assertEquals('1 out of 4 rows were imported.', $messages['Result']);
    $contributions = $this->callAPISuccess('Contribution', 'get', array('contact_id' => $minnie['id']));
    $this->assertEquals(0, $contributions['count']);

    $contributions = $this->callAPISuccess('Contribution', 'get', array('contact_id' => $betterMinnie['id']));
    $this->assertEquals(1, $contributions['count']);
    $relationships = $this->callAPISuccess('Relationship', 'get', array('contact_id_a' => $betterMinnie['id'], 'contact_id_b' => $organization['id']));
    $this->assertEquals(1, $relationships['count']);
  }

  /**
   * Test that we will accept a name match for employees, even when there is an email mis-match.
   *
   * We have a situation where employees are often in the database with a different email than in
   * the Benevity import (e.g a personal email). If there is already a contact with the same first and
   * last name and they have been related to the organization (by an employer relationship or a previous
   * soft credit) we should accept them.
   */
  function testImportSucceedIndividualMatchToEmployerDisregardingEmail() {
    $organization = $this->callAPISuccess('Contact', 'create', array('organization_name' => 'Mickey Mouse Inc', 'contact_type' => 'Organization'));
    $betterMinnie = $this->callAPISuccess('Contact', 'create', array(
      'first_name' => 'Minnie', 'last_name' => 'Mouse', 'contact_type' => 'Individual', 'email' => 'minnie@mouse_home.org', 'employer_id' => $organization['id'],
    ));
    $importer = new BenevityFile( __DIR__ . "/data/benevity.csv" );
    $importer->import();
    $messages = $importer->getMessages();
    $this->assertEquals('1 out of 4 rows were imported.', $messages['Result']);

    $contributions = $this->callAPISuccess('Contribution', 'get', array('contact_id' => $betterMinnie['id']));
    $this->assertEquals(1, $contributions['count']);
    $relationships = $this->callAPISuccess('Relationship', 'get', array('contact_id_a' => $betterMinnie['id'], 'contact_id_b' => $organization['id']));
    $this->assertEquals(1, $relationships['count']);
    $emails = $this->callAPISuccess('Email', 'get', array('contact_id' => $betterMinnie['id'], 'sequential' => 1));
    $this->assertEquals(2, $emails['count']);
    $this->assertEquals(1, $emails['values'][0]['is_primary']);
    $this->assertEquals('minnie@mouse_home.org', $emails['values'][0]['email']);
    $this->assertEquals(0, $emails['values'][1]['is_primary']);
    $this->assertEquals('minnie@mouse.org', $emails['values'][1]['email']);
  }


  /**
   * Check that without an email the match is only accepted with an employer connection.
   */
  function testImportSucceedIndividualOneMatchNoEmail() {
    $organization = $this->callAPISuccess('Contact', 'create', array('organization_name' => 'Mickey Mouse Inc', 'contact_type' => 'Organization'));
    $minnie = $this->callAPISuccess('Contact', 'create', array(
      'first_name' => 'Minnie', 'last_name' => 'Mouse', 'contact_type' => 'Individual', 'email' => 'minnie@mouse.org',
    ));
    $importer = new BenevityFile( __DIR__ . "/data/benevity_mice_no_email.csv" );
    $importer->import();
    $messages = $importer->getMessages();
    $this->assertEquals('0 out of 2 rows were imported.', $messages['Result']);

    $betterMinnie = $this->callAPISuccess('Contact', 'create', array(
      'first_name' => 'Minnie', 'last_name' => 'Mouse', 'contact_type' => 'Individual',
      'email' => 'minnie@mouse.org', 'employer_id' => $organization['id'],
    ));

    $importer = new BenevityFile( __DIR__ . "/data/benevity_mice_no_email.csv" );
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
        wmf_civicrm_get_custom_field_name('Campaign')
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
        wmf_civicrm_get_custom_field_name('Campaign')
      ),
    ));
    foreach ($organizationContributions['values'] as $contribution) {
      $this->assertEquals(1, $contribution[wmf_civicrm_get_custom_field_name('no_thank_you')]);
      $this->assertEquals('Restricted - Foundation', $contribution[wmf_civicrm_get_custom_field_name('Fund')]);
      $this->assertEquals('Matching Gift', $contribution[wmf_civicrm_get_custom_field_name('Campaign')]);
    }

  }

  /**
   * Test that rollback works.
   *
   * If part of the transaction fails it should be fully rolled back. Here we
   * ensure the second transaction fails and the created individual and created
   * contribution have also been rolled back.
   */
  function testImportDuplicateFullRollback() {
    $organization = $this->callAPISuccess('Contact', 'create', array('organization_name' => 'Mickey Mouse Inc', 'contact_type' => 'Organization'));
    // This will clash with the second transaction causing it to fail.

    $this->callAPISuccess('Contribution', 'create', array(
      'trxn_id' => 'BENEVITY TRXN-SQUEAK_MATCHED',
      'financial_type_id' => 'Engage',
      'total_amount' => 5,
      'contact_id' => $organization['id'],
    ));
    $importer = new BenevityFile( __DIR__ . "/data/benevity.csv" );
    $importer->import();
    $messages = $importer->getMessages();
    $this->assertEquals('0 out of 4 rows were imported.', $messages['Result']);
    $contribution = $this->callAPISuccess('Contribution', 'get', array('trxn_id' => 'BENEVITY TRXN-SQUEAK'));
    $this->assertEquals(0, $contribution['count'], 'This contribution should have been rolled back');
    $minnie = $this->callAPISuccess('Contact', 'get', array('first_name' => 'Minnie', 'last_name' => 'Mouse'));
    $this->assertEquals(0, $minnie['count'], 'This contact should have been rolled back');
  }

  /**
   * Test import succeeds if there is exactly one organization with the name as a nick name.
   *
   * If this is the case then the presence of other organizations with that name as a name
   * should not be a problem.
   */
  function testImportSucceedOrganizationDisambiguatedBySingleNickName() {
    $this->callAPISuccess('Contact', 'create', array('organization_name' => 'Donald Duck Inc', 'contact_type' => 'Organization'));
    $theRealDuck = $this->callAPISuccess('Contact', 'create', array('organization_name' => 'Donald Duck', 'nick_name' => 'Donald Duck Inc', 'contact_type' => 'Organization'));
    $this->callAPISuccess('Contact', 'create', array('organization_name' => 'Donald Duck Inc', 'contact_type' => 'Organization'));

    $importer = new BenevityFile( __DIR__ . "/data/benevity.csv" );
    $importer->import();
    $messages = $importer->getMessages();
    $this->assertEquals('1 out of 4 rows were imported.', $messages['Result']);
    $contribution = $this->callAPISuccessGetSingle('Contribution', array('trxn_id' => 'BENEVITY TRXN-QUACK'));
    $this->assertEquals(200, $contribution['total_amount']);

    $address = $this->callAPISuccess('Address', 'get', array('contact_id' => $contribution['contact_id'], 'sequential' => TRUE));
    $this->assertEquals('2 Quacker Road', $address['values'][0]['street_address']);
    $this->assertEquals('Duckville', $address['values'][0]['city']);
    $this->assertEquals(90210, $address['values'][0]['postal_code']);

    $orgContributions = $this->callAPISuccess('Contribution', 'get', array('contact_id' => $theRealDuck['id']));
    // The first row has no matching contribution.
    $this->assertEquals(0, $orgContributions['count']);

  }

  /**
   * Test that all imports fail if the organization has multiple matches.
   */
  function testImportSucceedOrganizationAll() {
    $mouseOrg = $this->callAPISuccess('Contact', 'create', array('organization_name' => 'Mickey Mouse Inc', 'contact_type' => 'Organization'));
    $dogOrg = $this->callAPISuccess('Contact', 'create', array('organization_name' => 'Goofy Inc', 'contact_type' => 'Organization'));
    $this->callAPISuccess('Contact', 'create', array('organization_name' => 'Donald Duck Inc', 'contact_type' => 'Organization'));
    $stingyOrg = $this->callAPISuccess('Contact', 'create', array('organization_name' => 'Uncle Scrooge Inc', 'contact_type' => 'Organization'));

    $this->callAPISuccess('Contact', 'create', array(
      'first_name' => 'Minnie', 'last_name' => 'Mouse', 'contact_type' => 'Individual', 'email' => 'minnie@mouse.org', 'employer_id' => $mouseOrg['id'],
    ));

    $this->callAPISuccess('Contact', 'create', array(
      'first_name' => 'Pluto', 'contact_type' => 'Individual', 'employer_id' => $dogOrg['id'],
    ));

    $importer = new BenevityFile( __DIR__ . "/data/benevity.csv" );
    $importer->import();
    $messages = $importer->getMessages();
    $this->assertEquals('All rows were imported', $messages['Result']);

    $contribution = $this->callAPISuccessGetSingle('Contribution', array('trxn_id' => 'BENEVITY trxn-WOOF'));
    $this->assertEquals(22, $contribution['total_amount']);
    $this->assertEquals(22, $contribution['net_amount']);

    $dogContact = $this->callAPISuccessGetSingle('Contact', array('id' => $contribution['contact_id']));
    $dogContributions = $this->callAPISuccess('Contribution', 'get', array('contact_id' => $dogContact['id']));
    $this->assertEquals(1, $dogContributions['count']);
    $this->assertTrue(empty($dogContributions['values'][$dogContributions['id']]['soft_credit']));
    $dogHouse = $this->callAPISuccess('Address', 'get', array('contact_id' => $dogContact['id'], 'sequential' => 1));
    $this->assertEquals(0, $dogHouse['count']);

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
      'contact_id' => $orgContributions['values'][$orgContributions['id']]['contact_id'])
    );
    $this->assertEquals(0, $organizationAddress['count']);

    $anonymousContact = $this->callAPISuccessGetSingle('Contact', array('email' => 'fakeemail@wikimedia.org'));
    $this->assertEquals('Anonymous', $anonymousContact['first_name']);
    $this->assertEquals('Anonymous', $anonymousContact['last_name']);
    // Let's not soft credit anonymouse.
    $this->assertTrue(empty($orgContributions['values'][$orgContributions['id']]['soft_credit_to']));
    $relationships = $this->callAPISuccess('Relationship', 'get', array('contact_id_a' => $anonymousContact['id']));
    $this->assertEquals(0, $relationships['count']);

    $mice = $this->callAPISuccess('Contact', 'get', array('first_name' => 'Minnie', 'last_name' => 'Mouse'));
    $minnie = $mice['values'][$mice['id']];
    $this->assertEquals('2 Cheesey Place', $minnie['street_address']);
    $this->assertEquals('Mickey Mouse Inc', $minnie['current_employer']);
    $relationships = $this->callAPISuccess('Relationship', 'get', array('contact_id_a' => $minnie['id']));
    $this->assertEquals(1, $relationships['count']);
  }

}

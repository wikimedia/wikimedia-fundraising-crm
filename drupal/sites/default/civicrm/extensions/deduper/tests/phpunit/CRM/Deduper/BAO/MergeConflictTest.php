<?php

use Civi\Api4\Address;
use Civi\Api4\OptionValue;
use Civi\Api4\Phone;
use Civi\Test\Api3TestTrait;
use Civi\Api4\Email;
use Civi\Test\EntityTrait;

require_once __DIR__ . '/../DedupeBaseTestClass.php';

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
class CRM_Deduper_BAO_MergeConflictTest extends DedupeBaseTestClass {

  use Api3TestTrait;
  use EntityTrait;

  /**
   * Setup for class.
   */
  public function setUp(): void {
    parent::setUp();
    $this->setSetting('deduper_resolver_field_prefer_preferred_contact', ['source']);
    $this->setSetting('deduper_resolver_preferred_contact_resolution', ['most_recent_contributor']);
    // Make sure we don't have any lingering batch-merge-able contacts in the db.
    $this->callAPISuccess('Job', 'process_batch_merge', ['mode' => 'safe']);
  }

  /**
   * Test the boolean resolver works.
   *
   * @throws \CRM_Core_Exception
   */
  public function testGetBooleanFields() {
    $fields = CRM_Deduper_BAO_MergeConflict::getBooleanFields();
    $this->assertTrue(isset($fields['do_not_mail'], $fields['on_hold']));
    $this->assertFalse(isset($fields['contact_type']));
    $this->assertFalse(isset($fields['is_deleted']));
  }

  /**
   * Test the boolean resolver works.
   *
   * @throws \CRM_Core_Exception
   */
  public function testGetContactFields() {
    $fields = CRM_Deduper_BAO_MergeConflict::getContactFields();
    $this->assertTrue(isset($fields['source']));
    $this->assertFalse(isset($fields['source'], $fields['street_address']));
  }

  /**
   * Test that a boolean field is resolved if set.
   */
  public function testResolveBooleanFields() {
    $this->createDuplicateIndividuals([['do_not_mail' => 0], ['do_not_mail' => 1]]);
    $this->callAPISuccess('Contact', 'merge', ['to_keep_id' => $this->ids['Contact'][0], 'to_remove_id' => $this->ids['Contact'][1]]);
    $mergedContacts = $this->callAPISuccess('Contact', 'get', ['id' => ['IN' => $this->ids['Contact']]])['values'];

    $this->assertEquals(1, $mergedContacts[$this->ids['Contact'][1]]['contact_is_deleted']);
    $this->assertEquals(0, $mergedContacts[$this->ids['Contact'][0]]['contact_is_deleted']);
    $this->assertEquals(1, $mergedContacts[$this->ids['Contact'][0]]['do_not_mail']);

    // Now try merging a contact with 0 in that field into our retained contact.
    $this->createTestEntity('Contact', ['first_name' => 'bob', 'do_not_mail' => 0, 'contact_type' => 'Individual'], 2)['id'];
    $this->callAPISuccess('Contact', 'merge', ['to_keep_id' => $this->ids['Contact'][0], 'to_remove_id' => $this->ids['Contact'][2]]);
    $mergedContacts = $this->callAPISuccess('Contact', 'get', ['id' => ['IN' => $this->ids['Contact'], 'is_deleted' => 0]])['values'];

    $this->assertEquals(1, $mergedContacts[$this->ids['Contact'][0]]['do_not_mail']);

    $this->assertEquals(1, $mergedContacts[$this->ids['Contact'][2]]['contact_is_deleted']);
    $this->assertEquals(0, $mergedContacts[$this->ids['Contact'][0]]['contact_is_deleted']);
  }

  /**
   * Test that in aggressive mode we keep the values of the most preferred contact.
   *
   * We use most recently created contact as our resolver as that is the opposite to the default
   * behaviour without our hook.
   *
   * @param bool $isReverse
   *   Should we reverse which contact we merge into?
   *
   * @dataProvider booleanDataProvider
   */
  public function testResolveAggressivePreferredContact(bool $isReverse) {
    $this->setSetting('deduper_resolver_field_prefer_preferred_contact', ['source']);
    $this->setSetting('deduper_resolver_preferred_contact_resolution', ['most_recently_created_contact']);
    $this->createDuplicateDonors([['first_name' => 'Sally'], []]);
    $mergedContact = $this->doMerge($isReverse, TRUE);
    $this->assertEquals('Bob', $mergedContact['first_name']);
  }

  /**
   * Test that in safe mode we do not merge unresolved conflicts.
   *
   * @param bool $isReverse
   *   Should we reverse which contact we merge into?
   *
   * @dataProvider booleanDataProvider
   */
  public function testSafeModeDoesNotOverrideConflict(bool $isReverse) {
    $this->setSetting('deduper_resolver_field_prefer_preferred_contact', ['source']);
    $this->setSetting('deduper_resolver_preferred_contact_resolution', ['most_recently_created_contact']);
    $this->createDuplicateDonors([['first_name' => 'Sally'], []]);
    $this->doNotDoMerge($isReverse);
  }

  /**
   * Test the boolean field resolver resolves emails on hold.
   *
   * @param bool $isReverse
   *   Should we reverse which contact has on_hold set to true?
   *
   * @dataProvider booleanDataProvider
   */
  public function testResolveEmailOnHold(bool $isReverse): void {
    $this->createDuplicateIndividuals();
    // Conveniently our 2 contacts are 0 & 1 in the $this->ids['Contact'] array, so we can abuse the boolean var like this.
    $contactIDOnHold = $isReverse;

    $email1 = $this->callAPISuccess('Email', 'get', ['contact_id' => $this->ids['Contact'][$contactIDOnHold]])['id'];
    $this->callAPISuccess('Email', 'create', ['id' => $email1, 'on_hold' => 1]);

    $mergeResult = $this->callAPISuccess('Contact', 'merge', ['to_keep_id' => $this->ids['Contact'][0], 'to_remove_id' => $this->ids['Contact'][1]])['values'];
    $this->assertCount(1, $mergeResult['merged']);
    $email0 = $this->callAPISuccessGetSingle('Email', ['contact_id' => ['IN' => [$this->ids['Contact'][0]]]]);
    $this->assertEquals(1, $email0['on_hold']);
  }

  /**
   * Data provider for tests with 2 options
   *
   * @return array
   */
  public function booleanDataProvider(): array {
    return [[FALSE], [TRUE]];
  }

  /**
   * Test resolving an initial in the first name.
   *
   * @param bool $isReverse
   *   Should we reverse which contact we merge into?
   *
   * @dataProvider booleanDataProvider
   */
  public function testInitialResolution(bool $isReverse): void {
    $this->createDuplicateIndividuals([['first_name' => 'Bob M'], []]);
    $mergedContact = $this->doMerge($isReverse);
    $this->assertEquals('Bob', $mergedContact['first_name']);
    $this->assertEquals('M', $mergedContact['middle_name']);
  }

  /**
   * Test resolving an initial in the last name.
   *
   * @param bool $isReverse
   *   Should we reverse which contact we merge into?
   *
   * @dataProvider booleanDataProvider
   */
  public function testInitialResolutionInLast(bool $isReverse) {
    $this->createDuplicateIndividuals([['last_name' => 'M Smith'], []]);
    $mergedContact = $this->doMerge($isReverse);
    $this->assertEquals('Bob', $mergedContact['first_name']);
    $this->assertEquals('M', $mergedContact['middle_name']);
    $this->assertEquals('Smith', $mergedContact['last_name']);
  }

  /**
   * Test resolving an initial in the first name.
   *
   * @param bool $isReverse
   *   Should we reverse which contact we merge into?
   *
   * @dataProvider booleanDataProvider
   */
  public function testInitialResolutionInLastWhenMiddleInitialInOther(bool $isReverse): void {
    $this->createDuplicateIndividuals([['last_name' => 'M Smith'], ['middle_name' => 'M']]);
    $mergedContact = $this->doMerge($isReverse);
    $this->assertEquals('Bob', $mergedContact['first_name']);
    $this->assertEquals('M', $mergedContact['middle_name']);
    $this->assertEquals('Smith', $mergedContact['last_name']);
  }

  /**
   * Test resolving an initial in the first name.
   *
   * @param bool $isReverse
   *   Should we reverse which contact we merge into?
   *
   * @dataProvider booleanDataProvider
   */
  public function testInitialResolutionNameIsInitial(bool $isReverse): void {
    $this->createDuplicateIndividuals([['last_name' => 'S', 'first_name' => 'B'], []]);
    $mergedContact = $this->doMerge($isReverse);
    $this->assertEquals('Bob', $mergedContact['first_name']);
    $this->assertEquals('Smith', $mergedContact['last_name']);
  }

  /**
   * Test resolving mis-cased names with an uninformative character.
   *
   * @param bool $isReverse
   *   Should we reverse which contact we merge into?
   *
   * @dataProvider booleanDataProvider
   */
  public function testCustomGreetingMismatch(bool $isReverse): void {
    $emailGreetings = OptionValue::get(FALSE)
      ->addWhere('option_group_id:name', '=', 'email_greeting')
      ->addSelect('id', 'name', 'is_default', 'value')
      ->addOrderBy('is_default', 'DESC')
      ->execute()->indexBy('name');

    $postalGreetings = OptionValue::get(FALSE)
      ->addWhere('option_group_id:name', '=', 'postal_greeting')
      ->addSelect('id', 'name', 'is_default', 'value')
      ->addOrderBy('is_default', 'DESC')
      ->execute()->indexBy('name');

    $addressee = OptionValue::get(FALSE)
      ->addWhere('option_group_id:name', '=', 'addressee')
      ->addSelect('id', 'name', 'is_default', 'value')
      ->addOrderBy('is_default', 'DESC')
      ->execute()->indexBy('name');

    $this->createDuplicateIndividuals([
      [
        'email_greeting_id' => $emailGreetings->first()['value'],
        'postal_greeting_id' => $postalGreetings->first()['value'],
        'addressee_id' => $addressee->first()['value'],
      ],
      [
        'email_greeting_id' => $emailGreetings['Customized']['value'],
        'postal_greeting_id' => $postalGreetings['Customized']['value'],
        'addressee_id' => $addressee['Customized']['value'],
        'email_greeting_custom' => 'Bob',
        'postal_greeting_custom' => 'Bob',
        'addressee_custom' => 'Bob',
      ],
    ]);
    $mergedContact = $this->doMerge($isReverse);
    $this->assertEquals('Bob', $mergedContact['first_name']);
    $this->assertEquals('Smith', $mergedContact['last_name']);
  }

  /**
   * Test resolving an initial in the first name.
   *
   * @param bool $isReverse
   *   Should we reverse which contact we merge into?
   *
   * @dataProvider booleanDataProvider
   */
  public function testInitialResolutionInitialWithCasingConflict(bool $isReverse): void {
    $this->createDuplicateIndividuals([['last_name' => 'M SMITH'], []]);
    $mergedContact = $this->doMerge($isReverse);
    $this->assertEquals('Bob', $mergedContact['first_name']);
    $this->assertEquals('Smith', $mergedContact['last_name']);
    $this->assertEquals('M', $mergedContact['middle_name']);
  }

  /**
   * Test resolving an initial in the first name when the other contact already has the same value as an initial
   *
   * @param bool $isReverse
   *   Should we reverse which contact we merge into?
   *
   * @dataProvider booleanDataProvider
   */
  public function testInitialResolutionNameInitialExists(bool $isReverse): void {
    $this->createDuplicateIndividuals([['first_name' => 'Bob J'], ['middle_name' => 'J']]);
    $mergedContact = $this->doMerge($isReverse);
    $this->assertEquals('Bob', $mergedContact['first_name']);
    $this->assertEquals('Smith', $mergedContact['last_name']);
    $this->assertEquals('J', $mergedContact['middle_name']);
  }

  /**
   * Test resolving an initial in the first name when the other contact already has the same value as an initial with a dot.
   *
   * i.e. [first_name => 'Bob J'] vs ['first_name' => 'Bob', 'middle_name' => 'J.']
   *
   * @param bool $isReverse
   *   Should we reverse which contact we merge into?
   *
   * @dataProvider booleanDataProvider
   */
  public function testInitialResolutionNameInitialExistsDotted(bool $isReverse): void {
    $this->createDuplicateIndividuals([['first_name' => 'Bob J.'], ['middle_name' => 'J.']]);
    $mergedContact = $this->doMerge($isReverse);
    $this->assertEquals('Bob', $mergedContact['first_name']);
    $this->assertEquals('Smith', $mergedContact['last_name']);
    $this->assertEquals('J', $mergedContact['middle_name']);
  }

  /**
   * Test resolving mis-cased names with an uninformative character.
   *
   * @param bool $isReverse
   *   Should we reverse which contact we merge into?
   *
   * @dataProvider booleanDataProvider
   */
  public function testUninformativeCharactersWithCasingDifference(bool $isReverse): void {
    $this->createDuplicateIndividuals([['last_name' => 'Gold Smith'], ['last_name' => 'golD-sMith']]);
    $mergedContact = $this->doMerge($isReverse);
    $this->assertEquals('Bob', $mergedContact['first_name']);
    // We are not doing any preference handling here so as long as it is one of them it's OK.
    if ($isReverse) {
      $this->assertEquals('golD-sMith', $mergedContact['last_name']);
    }
    else {
      $this->assertEquals('Gold Smith', $mergedContact['last_name']);
    }
  }

  /**
   * Test that we don't allow silly names to create a conflict.
   *
   * Sometimes people enter cruft like 'first' for first_name. Later they
   * enter their real names. We can ignore known silly names when resolving conflicts.
   *
   * @param bool $isReverse
   *   Should we reverse which contact we merge into?
   *
   * @dataProvider booleanDataProvider
   */
  public function testIgnoreSillyNames(bool $isReverse): void {
    $this->createDuplicateIndividuals([['first_name' => 'first'], []]);
    $mergedContact = $this->doMerge($isReverse);
    $this->assertEquals('Bob', $mergedContact['first_name']);
  }

  /**
   * Test that contacts with known better options are merged.
   *
   * Here we rely on a table of contacts to identify which are matches - e.g
   * 'Benjamain' is a misspelling of Benjamin. The pair is in the table
   * AND we know Benjamin is preferred.
   *
   * @param bool $isReverse
   *   Should we reverse which contact we merge into?
   *
   * @dataProvider booleanDataProvider
   *
   * @noinspection SpellCheckingInspection
   */
  public function testMergeMisSpeltContacts(bool $isReverse) {
    $this->createDuplicateIndividuals([['first_name' => 'Benjamin'], ['first_name' => 'Benjamain']]);
    $mergedContact = $this->doMerge($isReverse);
    $this->assertEquals('Benjamin', $mergedContact['first_name']);
  }

  /**
   * Test that contacts can be resolved to the nickname, setting dependent.
   *
   * @param bool $isReverse
   *   Should we reverse which contact we merge into?
   *
   * @dataProvider booleanDataProvider
   */
  public function testMergePreferNickName(bool $isReverse): void {
    $this->setSetting('deduper_equivalent_name_handling', 'prefer_nick_name');
    $this->setSetting('deduper_resolver_preferred_contact_resolution', ['earliest_created_contact']);
    $this->createDuplicateIndividuals([['first_name' => 'Theodore'], ['first_name' => 'Ted']]);
    $mergedContact = $this->doMerge($isReverse);
    $this->assertEquals('Ted', $mergedContact['first_name']);
  }

  /**
   * Test that contacts can be resolved to the non nickname, setting dependent.
   *
   * @param bool $isReverse
   *   Should we reverse which contact we merge into?
   *
   * @dataProvider booleanDataProvider
   */
  public function testMergePreferNonNickName(bool $isReverse): void {
    $this->setSetting('deduper_equivalent_name_handling', 'prefer_non_nick_name');
    $this->createDuplicateIndividuals([['first_name' => 'Theodore'], ['first_name' => 'Ted']]);
    $mergedContact = $this->doMerge($isReverse);
    $this->assertEquals('Theodore', $mergedContact['first_name']);
  }

  /**
   * Test that contacts can be resolved to the non nickname, setting dependent.
   *
   * @param bool $isReverse
   *   Should we reverse which contact we merge into?
   *
   * @dataProvider booleanDataProvider
   */
  public function testMergePreferNonNickNameKeepNickName(bool $isReverse): void {
    $this->setSetting('deduper_equivalent_name_handling', 'prefer_non_nick_name_keep_nick_name');
    $this->createDuplicateIndividuals([['first_name' => 'Theodore'], ['first_name' => 'Ted']]);
    $mergedContact = $this->doMerge($isReverse);
    $this->assertEquals('Theodore', $mergedContact['first_name']);
    $this->assertEquals('Ted', $mergedContact['nick_name'], 'Nick name should be set');
  }

  /**
   * Test that contacts can be resolved to the non nickname, setting dependent.
   *
   * @param bool $isReverse
   *   Should we reverse which contact we merge into?
   *
   * @dataProvider booleanDataProvider
   */
  public function testMergePreferredContactNonNickNameKeepNickName(bool $isReverse): void {
    $this->setSetting('deduper_equivalent_name_handling', 'prefer_preferred_contact_value_keep_nick_name');
    $this->setSetting('deduper_resolver_preferred_contact_resolution', ['most_recent_contributor']);
    $this->createDuplicateDonors([['first_name' => 'Theodore'], ['first_name' => 'Ted']]);
    $mergedContact = $this->doMerge($isReverse);
    $this->assertEquals('Theodore', $mergedContact['first_name']);
    $this->assertEquals('Ted', $mergedContact['nick_name']);
  }

  /**
   *  Test resolving a situation where the first name is duplicated in the full name.
   *
   * e.g
   * ['first_name' => 'Bob', 'last_name' => 'Bob Max Smith'],
   * ['first_name' => 'Bob', 'last_name' => 'Max Smith']
   *
   * @param bool $isReverse
   *   Should we reverse which contact we merge into?
   *
   * @dataProvider booleanDataProvider
   */
  public function testMisplacedNameResolutionFullNameInFirstName(bool $isReverse): void {
    $this->createDuplicateIndividuals([['last_name' => NULL, 'first_name' => 'Bob M Smith'], []]);
    $this->setSetting('deduper_resolver_preferred_contact_resolution', ['earliest_created_contact']);
    $mergedContact = $this->doMerge($isReverse);
    $this->assertEquals('Bob', $mergedContact['first_name']);
    $this->assertEquals('Smith', $mergedContact['last_name']);
    $this->assertEquals('M', $mergedContact['middle_name']);
  }

  /**
   * Test resolving an initial in the first name.
   *
   * @param bool $isReverse
   *   Should we reverse which contact we merge into?
   *
   * @param array $data
   *
   * @dataProvider mergeConflictProvider
   *
   */
  public function testMisplacedNameResolutions(bool $isReverse, array $data): void {
    $this->createDuplicateIndividuals([$data['contact_1'], $data['contact_2']]);
    $mergedContact = $this->doMerge($isReverse);
    $this->assertEquals('Bob', $mergedContact['first_name']);
    $this->assertEquals('Smith', $mergedContact['last_name']);
    $this->assertEquals($data['expected']['middle_name'], $mergedContact['middle_name']);
  }

  /**
   * Get data to test merge conflicts on.
   *
   * Note that the default for first_name is Bob & for last name Smith - only
   * overrides are set.
   *
   * Returns an  array with the reverse boolean plus contact inputs.
   */
  public function mergeConflictProvider(): array {
    $dataset = [];
    $dataset[] = [
      'contact_1' => ['last_name' => 'M J Smith'],
      'contact_2' => ['middle_name' => NULL],
      'expected' => ['middle_name' => 'M J'],
    ];
    $dataset[] = [
      'contact_1' => ['first_name' => NULL, 'last_name' => 'Bob M Smith'],
      'contact_2' => [],
    ];
    $dataset[] = [
      'contact_1' => ['first_name' => NULL, 'last_name' => 'Bob M Smith'],
      'contact_2' => ['middle_name' => 'M'],
    ];

    $return = [];
    $expected = ['first_name' => 'Bob', 'middle_name' => 'M', 'last_name' => 'Smith'];
    foreach ($dataset as $data) {
      $data['expected'] = array_merge($expected, $data['expected'] ?? []);
      $return[] = [FALSE, $data];
      $return[] = [TRUE, $data];
    }
    return $return;
  }

  /**
   * Test resolving an initial in the first name.
   *
   * @param bool $isReverse
   *   Should we reverse which contact we merge into?
   *
   * @dataProvider booleanDataProvider
   */
  public function testMisplacedNameResolutionFullRepeatedInLastName(bool $isReverse) {
    $this->createDuplicateIndividuals([['first_name' => 'Bob', 'last_name' => 'Bob Max Smith'], ['last_name' => 'Max Smith']]);
    $mergedContact = $this->doMerge($isReverse);
    $this->assertEquals('Bob', $mergedContact['first_name']);
    $this->assertEquals('Max Smith', $mergedContact['last_name']);
  }

  /**
   * Test resolving a name where the first name & last name are in reversed places.
   *
   * @param bool $isReverse
   *   Should we reverse which contact we merge into?
   *
   * @dataProvider booleanDataProvider
   *
   * @throws \CRM_Core_Exception
   */
  public function testMisplacedNameResolutionReversedNames(bool $isReverse) {
    // Put Bob Smith in the right order in the preferred Contact.
    $contact1 = $isReverse ? ['first_name' => 'Smith', 'last_name' => 'Bob'] : ['first_name' => 'Bob', 'last_name' => 'Smith'];
    $contact2 = $isReverse ? ['first_name' => 'Bob', 'last_name' => 'Smith'] : ['first_name' => 'Smith', 'last_name' => 'Bob'];
    $this->createDuplicateIndividuals([$contact1, $contact2]);
    $mergedContact = $this->doMerge($isReverse);
    $this->assertEquals('Bob', $mergedContact['first_name']);
    $this->assertEquals('Smith', $mergedContact['last_name']);
  }

  /**
   * Test resolving an initial in the first name with punctuation.
   *
   * @param bool $isReverse
   *   Should we reverse which contact we merge into?
   *
   * @dataProvider booleanDataProvider
   */
  public function testMisplacedNameResolutionWithPunctuation(bool $isReverse): void {
    $this->createDuplicateIndividuals([['first_name' => NULL, 'last_name' => 'Bob M. Smith'], []]);
    $mergedContact = $this->doMerge($isReverse);
    $this->assertEquals('Bob', $mergedContact['first_name']);
    $this->assertEquals('Smith', $mergedContact['last_name']);
    $this->assertEquals('M', $mergedContact['middle_name']);
  }

  /**
   * Test that a name field that is the same apart from white space can be resolved.
   *
   * @param bool $isReverse
   *   Should we reverse which contact we merge into?
   *
   * @dataProvider booleanDataProvider
   */
  public function testResolveWhiteSpaceInName(bool $isReverse) {
    $this->createDuplicateIndividuals([['first_name' => 'alter ego'], ['first_name' => 'alterego']]);
    $mergedContact = $this->doMerge($isReverse);
    $this->assertEquals('alter ego', $mergedContact['first_name']);
  }

  /**
   * Test resolving a field where we resolve by preferred contact.
   *
   * Use earliest created contact resolver.
   *
   * @param bool $isReverse
   *   Should we reverse which contact we merge into?
   *
   * @dataProvider booleanDataProvider
   */
  public function testResolvePreferredContactField(bool $isReverse): void {
    $this->setSetting('deduper_resolver_field_prefer_preferred_contact', ['source']);
    $this->setSetting('deduper_resolver_preferred_contact_resolution', ['earliest_created_contact']);
    $this->createDuplicateIndividuals([['source' => 'keep me'], ['source' => 'ditch me']]);
    $mergedContact = $this->doMerge($isReverse);
    $this->assertEquals('keep me', $this->callAPISuccessGetValue('Contact', ['return' => 'contact_source', 'id' => $mergedContact['id']]));
  }

  /**
   * Test resolving a field where we resolve by preferred contact.
   *
   * Use most recently created contact resolver.
   *
   * @param bool $isReverse
   *   Should we reverse which contact we merge into?
   *
   * @dataProvider booleanDataProvider
   */
  public function testResolvePreferredContactFieldChooseLatest(bool $isReverse): void {
    $this->setSetting('deduper_resolver_field_prefer_preferred_contact', ['source']);
    $this->setSetting('deduper_resolver_preferred_contact_resolution', ['most_recently_created_contact']);
    $this->createDuplicateIndividuals([['contact_source' => 'ditch me'], ['contact_source' => 'keep me']]);
    $mergedContact = $this->doMerge($isReverse);
    $this->assertEquals('keep me', $this->callAPISuccessGetValue('Contact', ['return' => 'contact_source', 'id' => $mergedContact['id']]));
  }

  /**
   * Test resolving a field where we resolve by preferred contact.
   *
   * Use most recent donor resolver.
   *
   * @param bool $isReverse
   *   Should we reverse which contact we merge into?
   *
   * @dataProvider booleanDataProvider
   */
  public function testResolvePreferredContactFieldChooseMostRecentDonor(bool $isReverse): void {
    $this->setSetting('deduper_resolver_field_prefer_preferred_contact', ['source']);
    $this->setSetting('deduper_resolver_preferred_contact_resolution', ['most_recent_contributor']);
    $this->createDuplicateDonors();
    $mergedContact = $this->doMerge($isReverse);
    $this->assertEquals('keep me', $this->callAPISuccessGetValue('Contact', ['return' => 'contact_source', 'id' => $mergedContact['id']]));
  }

  /**
   * Test resolving a field where we resolve by preferred contact.
   *
   * Use most prolific donor contact resolver.
   *
   * @param bool $isReverse
   *   Should we reverse which contact we merge into?
   *
   * @dataProvider booleanDataProvider
   */
  public function testResolvePreferredContactFieldChooseMostProlific(bool $isReverse): void {
    $this->setSetting('deduper_resolver_field_prefer_preferred_contact', ['source']);
    $this->setSetting('deduper_resolver_preferred_contact_resolution', ['most_prolific_contributor']);
    $this->createDuplicateDonors();
    // Add a second contribution to the first donor - making it more prolific.
    $this->createTestEntity('Contribution', ['financial_type_id:name' => 'Donation', 'total_amount' => 5, 'contact_id' => $this->ids['Contact'][0], 'receive_date' => '2019-08-08']);
    $mergedContact = $this->doMerge($isReverse);
    $this->assertEquals('keep me', $this->callAPISuccessGetValue('Contact', ['return' => 'contact_source', 'id' => $mergedContact['id']]));
  }

  /**
   * Test resolving email where we resolve by preferred contact.
   *
   * Use most recent donor resolver.
   *
   * @param bool $isReverse
   *   Should we reverse which contact we merge into?
   *
   * @throws \CRM_Core_Exception
   *
   * @dataProvider booleanDataProvider
   */
  public function testResolvePreferredContactEmail(bool $isReverse): void {
    $this->setSetting('deduper_resolver_field_prefer_preferred_contact', ['source']);
    $this->setSetting('deduper_resolver_preferred_contact_resolution', ['most_recent_contributor']);
    $this->createDuplicateDonors([[], ['email_primary.email' => 'notbob@example.com']]);
    $mergedContact = $this->doMerge($isReverse);
    $emails = Email::get(FALSE)->setSelect(['email', 'is_primary', 'location_type_id:name'])->addWhere('contact_id', '=', $mergedContact['contact_id'])->addOrderBy('is_primary', 'DESC')->execute();
    $this->assertCount(2, $emails);
    $this->assertEquals('bob@example.com', $emails[0]['email']);
    $this->assertEquals('notbob@example.com', $emails[1]['email']);
  }

  /**
   * This is testing an issue that cropped up where address merges were hitting type errors.
   *
   * @param bool $isReverse
   *   Should we reverse which contact we merge into?
   *
   * @throws \CRM_Core_Exception
   *
   * @dataProvider booleanDataProvider
   */
  public function testAddressMerge(bool $isReverse): void {
    $this->setSetting('deduper_resolver_address', 'preferred_contact');
    $this->createDuplicateDonors();
    // Delete the generic addresses & create the ones for this test.
    Address::delete(FALSE)->addWhere('contact_id', 'IN', $this->ids['Contact'])->execute();
    $this->createTestEntity('Address', [
      'contact_id' => $this->ids['Contact'][0],
      'street_address' => '意外だね42意外だね',
      'is_billing' => TRUE,
      'is_primary' => TRUE,
      'location_type_id:name' => 'Home',
      'city' => '意外だね',
      'postal_code' => 310027,
      'country_id:name' => 'China',
    ], 'contact_1_main');

    $this->createTestEntity('Address', [
      'contact_id' => $this->ids['Contact'][1],
      'street_address' => '33 Main Street',
      'is_billing' => TRUE,
      'location_type_id:name' => 'Other',
      'city' => 'Mega-ville',
      'state_province_id:name' => 'CA',
      'postal_code' => 90201,
      'country_id:name' => 'United States',
    ], 'contact_2_other');
    // Mess up the location type ID.
    // See https://lab.civicrm.org/dev/core/-/issues/5240.
    CRM_Core_DAO::executeQuery('UPDATE civicrm_address SET location_type_id = 0 WHERE id = ' . $this->ids['Address']['contact_2_other']);
    $this->createTestEntity('Address', [
      'contact_id' => $this->ids['Contact'][1],
      'street_address' => 'Home sweet home',
      'is_billing' => TRUE,
      'is_primary' => TRUE,
      'location_type_id:name' => 'Home',
      'city' => 'Mega-ville',
      'postal_code' => 90001,
      'country_id:name' => 'United States',
    ], 'contact_2_main');
    $contact = $this->doMerge($isReverse);

    $address = Address::get(FALSE)
      ->addWhere('contact_id', '=', $contact['id'])
      ->addSelect('street_address', 'is_primary', 'location_type_id:name', 'location_type_id')
      ->addOrderBy('is_primary', 'DESC')
      ->execute();
    $this->assertCount(2, $address);
    $this->assertEquals('Home', $address[0]['location_type_id:name']);
    $this->assertEquals('意外だね42意外だね', $address[0]['street_address']);
    $this->assertNotEmpty($address[1]['location_type_id']);
    $this->assertNotEquals($address[0]['location_type_id'], $address[1]['location_type_id']);
    $this->assertEquals('33 Main Street', $address[1]['street_address']);
  }

  /**
   * Test handling when 2 addresses with the same details are resolved.
   *
   * @param bool $isReverse
   *   Should we reverse which contact we merge into?
   *
   * @throws \CRM_Core_Exception
   *
   * @dataProvider booleanDataProvider
   */
  public function testAddressMergeWithLocationWrangling(bool $isReverse): void {
    $this->createDuplicateIndividuals();
    $this->createTestEntity('LocationType', ['name' => 'Another', 'display_name' => 'Another']);
    $this->createTestEntity('Contribution', [
      'receive_date' => 'now',
      'financial_type_id:name' => 'Donation',
      'total_amount' => 5,
      'contact_id' => $this->ids['Contact'][1],
    ]);
    $this->setSetting('deduper_resolver_preferred_contact_resolution', [
      'most_recent_contributor',
    ]);
    $this->setSetting('deduper_location_priority_order', [
      \CRM_Core_PseudoConstant::getKey('CRM_Core_BAO_Address', 'location_type_id', 'Billing'),
      \CRM_Core_PseudoConstant::getKey('CRM_Core_BAO_Address', 'location_type_id', 'Home'),
      \CRM_Core_PseudoConstant::getKey('CRM_Core_BAO_Address', 'location_type_id', 'Another'),
      \CRM_Core_PseudoConstant::getKey('CRM_Core_BAO_Address', 'location_type_id', 'Other'),
    ]);
    $this->setSetting('deduper_resolver_email', 'preferred_contact_with_re-assign');
    $this->setSetting('deduper_resolver_phone', 'preferred_contact_with_re-assign');
    $this->setSetting('deduper_resolver_address', 'preferred_contact_with_re-assign');

    $this->createTestEntity('Email', [
      'location_type_id:name' => 'Billing',
      'email' => 'move_mail1@example.com',
      'contact_id' => $this->ids['Contact'][0],
    ]);
    $this->createTestEntity('Email', [
      'location_type_id:name' => 'Billing',
      'email' => 'move_mail0@example.com',
      'contact_id' => $this->ids['Contact'][1],
    ]);
    $this->createTestEntity('Email', [
      'location_type_id:name' => 'Another',
      'email' => 'mail_3@example.com',
      'contact_id' => $this->ids['Contact'][1],
    ]);

    $this->createTestEntity('Phone', [
      'location_type_id:name' => 'Billing',
      'phone' => '1234',
      'contact_id' => $this->ids['Contact'][0],
    ]);
    $this->createTestEntity('Phone', [
      'location_type_id:name' => 'Billing',
      'phone' => '5678',
      'contact_id' => $this->ids['Contact'][1],
    ]);
    $this->createTestEntity('Phone', [
      'location_type_id:name' => 'Another',
      'phone' => '1288',
      'contact_id' => $this->ids['Contact'][1],
    ]);

    $this->createTestEntity('Address', [
      'location_type_id:name' => 'Billing',
      'street_address' => '1234 Main St',
      'contact_id' => $this->ids['Contact'][0],
    ]);
    $this->createTestEntity('Address', [
      'location_type_id:name' => 'Billing',
      'street_address' => '5678 Main St',
      'contact_id' => $this->ids['Contact'][1],
    ]);
    $this->createTestEntity('Address', [
      'location_type_id:name' => 'Another',
      'street_address' => '1288 Main St',
      'contact_id' => $this->ids['Contact'][1],
    ]);

    $contact = $this->doMerge($isReverse);
    $emails = Email::get(FALSE)
      ->addSelect('email', 'location_type_id:name', 'contact_id')
      ->addWhere('contact_id', '=', $contact['id'])
      ->execute()->indexBy('location_type_id:name');
    $this->assertCount(4, $emails);
    $this->assertEquals('move_mail1@example.com', $emails['Other']['email']);

    $this->assertCount(4, Address::get(FALSE)
      ->addSelect('street_address', 'location_type_id:name', 'contact_id')
      ->addWhere('contact_id', '=', $contact['id'])
      ->execute());

    $this->assertCount(4, Phone::get(FALSE)
      ->addSelect('phone', 'location_type_id:name', 'contact_id')
      ->addWhere('contact_id', '=', $contact['id'])
      ->execute());
  }

  /**
   * Test that we don't treat the addition of a postal suffix only as a conflict.
   *
   * Bug T177807
   *
   * @param bool $isReverse
   *   Should we reverse which contact we merge into?
   *
   * @dataProvider booleanDataProvider
   * @throws \CRM_Core_Exception
   */
  public function testBatchMergeResolvableConflictPostalSuffixExists(bool $isReverse): void {
    $this->setSetting('deduper_resolver_address', 'preferred_contact');
    $this->createDuplicateDonors();
    // Delete the generic addresses & create the ones for this test.
    Address::delete(FALSE)->addWhere('contact_id', 'IN', $this->ids['Contact'])->execute();
    $contactIDWithPostalSuffix = ($isReverse ? $this->ids['Contact'][1] : $this->ids['Contact'][0]);
    $contactIDWithOutPostalSuffix = ($isReverse ? $this->ids['Contact'][0] : $this->ids['Contact'][1]);
    $this->createTestEntity('Address', [
      'country_id:name' => 'MX',
      'contact_id' => $contactIDWithPostalSuffix,
      'location_type_id' => 1,
      'street_address' => 'First on the left after you cross the border',
      'postal_code' => 90210,
      'postal_code_suffix' => 6666,
    ]);
    $this->createTestEntity('Address', [
      'country_id:name' => 'MX',
      'contact_id' => $contactIDWithOutPostalSuffix,
      'street_address' => 'First on the left after you cross the border',
      'postal_code' => 90210,
      'location_type_id' => 1,
    ]);

    $contact = $this->doMerge($isReverse);
    $this->assertEquals('Mexico', $contact['country']);
    $this->assertEquals('6666', $contact['postal_code_suffix']);
    $this->assertEquals('90210', $contact['postal_code']);
    $this->assertEquals('First on the left after you cross the border', $contact['street_address']);
  }

  /**
   * Test that we don't see country only as conflicting with country plus.
   *
   * Bug T176699
   *
   * @param bool $isReverse
   *   Should we reverse which contact we merge into?
   *
   * @dataProvider booleanDataProvider
   */
  public function testBatchMergeResolvableConflictCountryVsFullAddress(bool $isReverse): void {
    $this->setSetting('deduper_resolver_address', 'preferred_contact');
    $this->createDuplicateDonors();
    $contactIDWithCountryOnlyAddress = ($isReverse ? $this->ids['Contact'][1] : $this->ids['Contact'][0]);
    $contactIDWithFullAddress = ($isReverse ? $this->ids['Contact'][0] : $this->ids['Contact'][1]);
    $this->createTestEntity('Address', [
      'country_id:name' => 'MX',
      'contact_id' => $contactIDWithCountryOnlyAddress,
      'location_type_id' => 1,
      'is_primary' => 1,
    ]);
    $this->createTestEntity('Address', [
      'country_id:name' => 'MX',
      'contact_id' => $contactIDWithFullAddress,
      'street_address' => 'First on the left after you cross the border',
      'location_type_id' => 1,
      'is_primary' => 1,
    ]);
    $this->createTestEntity('Address', [
      'country_id:name' => 'MX',
      'contact_id' => $contactIDWithFullAddress,
      'street_address' => 'A different address',
      'location_type_id' => 2,
    ]);

    $contact = $this->doMerge($isReverse);
    $this->assertEquals('Mexico', $contact['country']);
    $this->assertEquals('First on the left after you cross the border', $contact['street_address']);
    $address = $this->callAPISuccessGetSingle('Address', ['street_address' => 'A different address']);
    $this->assertEquals($contact['id'], $address['contact_id']);
    $this->callAPISuccessGetCount('Address', ['contact_id' => $contact['id'], 'is_primary' => 1], 1);
  }

  /**
   * Test that a conflict on casing in first names is handled.
   *
   * We do a best-effort on this to get the more correct on assuming that 1 capital letter in a
   * name is most likely to be deliberate. We prioritise less capital letters over more, except that
   * all lower case is at the end of the queue.
   *
   * This won't necessarily give us the best results for 'La Plante' vs 'la Plante' but we should bear in mind
   * - both variants have been entered by the user at some point, so they have not 'chosen' one.
   * - having 2 variants of the spelling of a name with more than one upper case letter in our
   * db is an edge case.
   */
  public function testCasingConflicts(): void {
    $this->createDuplicateIndividuals([
      ['first_name' => 'donald', 'last_name' => 'Duck'],
      ['first_name' => 'Donald', 'last_name' => 'duck'],
      ['first_name' => 'DONALD', 'last_name' => 'DUCK'],
      ['first_name' => 'DonalD', 'last_name' => 'DUck'],
    ]);
    $mergedContact = $this->doBatchMerge($this->ids['Contact'][0], ['skipped' => 0, 'merged' => 3]);
    $this->assertEquals('Donald', $mergedContact['first_name']);
    $this->assertEquals('Duck', $mergedContact['last_name']);
  }

  /**
   * Make sure José whomps Jose.
   *
   * Test diacritic matches are resolved to the one using 'authentic' characters.
   *
   * @param array $names
   * @param bool $isMatch
   * @param string|null $preferredName
   *
   * @dataProvider getDiacriticData
   */
  public function testDiacriticConflicts(array $names, bool $isMatch, ?string $preferredName) {
    $this->createDuplicateIndividuals([
      ['first_name' => $names[0]],
      ['first_name' => $names[1]],
    ]);
    $mergedContact = $this->doBatchMerge($this->ids['Contact'][0], ['skipped' => (int) !$isMatch, 'merged' => (int) $isMatch]);
    if ($isMatch) {
      $this->assertEquals($preferredName, $mergedContact['first_name']);
    }
  }

  /**
   * Get names with different character sets.
   */
  public function getDiacriticData(): array {
    $dataSet = [];
    $dataSet['cyrilic_dissimilar_hyphenated'] = [
      'pair' => ['Леони-́дович', 'ни́-тский'],
      'is_match' => FALSE,
      'choose' => NULL,
    ];
    $dataSet['germanic'] = [
      'pair' => ['Boß', 'Boss'],
      'is_match' => TRUE,
      'choose' => 'Boß',
    ];
    $dataSet['germanic_reverse'] = [
      'pair' => ['Boss', 'Boß'],
      'is_match' => TRUE,
      'choose' => 'Boß',
    ];
    $dataSet['accent_vs_no_accent'] = [
      'pair' => ['Jose', 'Josè'],
      'is_match' => TRUE,
      'choose' => 'Josè',
    ];
    $dataSet['accent_vs_no_accent_reverse'] = [
      'pair' => ['José', 'Jose'],
      'is_match' => TRUE,
      'choose' => 'José',
    ];
    $dataSet['different_direction_accent'] = [
      'pair' => ['Josè', 'José'],
      'is_match' => TRUE,
      // No preference applied, first wins.
      'choose' => 'Josè',
    ];
    $dataSet['no_way_jose'] = [
      'pair' => ['Jose', 'Josà'],
      'is_match' => FALSE,
      'choose' => NULL,
    ];
    $dataSet['cyric_sergei'] = [
      'pair' => ['Серге́й', 'Sergei'],
      // Actually this is a real translation but will not
      // match at our level of sophistication.
      'is_match' => FALSE,
      'choose' => NULL,
    ];
    $dataSet['cyric_sergai'] = [
      'pair' => ['Серге́й', 'Sergi'],
      // Actually this is a real translation but will not
      // match at our level of sophistication.
      'is_match' => FALSE,
      'choose' => NULL,
    ];
    $dataSet['cyrilic_different_length'] = [
      'pair' => ['Серге́й', 'Серге'],
      'is_match' => FALSE,
      'choose' => NULL,
    ];
    $dataSet['cyrilic_dissimilar'] = [
      'pair' => ['Леони́дович', 'ни́тский'],
      'is_match' => FALSE,
      'choose' => NULL,
    ];
    return $dataSet;
  }

  /**
   * Create individuals to dedupe.
   *
   * @param array $contactParams
   *   Arrays of parameters, one per contact.
   */
  private function createDuplicateIndividuals(array $contactParams = [[], []]) {
    $params = [
      'first_name' => 'Bob',
      'last_name' => 'Smith',
      'contact_type' => 'Individual',
      'email_primary.email' => 'bob@example.com',
      'phone_primary.phone' => 123,
      'address_primary.street_address' => 'Home sweet home',
    ];
    foreach ($contactParams as $index => $contactParam) {
      $contactParam = array_merge($params, $contactParam);
      $this->createTestEntity('Contact', $contactParam, $index)['id'];
    }
  }

  /**
   * Action the merge of the 2 created contacts.
   *
   * @param bool $isReverse
   *   Is the order to be reversed - i.e. merge contact 0 into 1 rather than 1 into 0.
   *   It is good practice to do all dedupe tests twice using this reversal to cover
   *   both scenarios.
   */
  protected function doNotDoMerge(bool $isReverse): void {
    $toKeepContactID = $isReverse ? $this->ids['Contact'][1] : $this->ids['Contact'][0];
    $toDeleteContactID = $isReverse ? $this->ids['Contact'][0] : $this->ids['Contact'][1];
    $mergeResult = $this->callAPISuccess('Contact', 'merge', ['to_keep_id' => $toKeepContactID, 'to_remove_id' => $toDeleteContactID])['values'];
    $this->assertCount(1, $mergeResult['skipped']);
    $this->assertCount(0, $mergeResult['merged']);
  }

  /**
   * Action the merge of the 2 created contacts.
   *
   * @param bool $isReverse
   *   Is the order to be reversed - i.e. merge contact 0 into 1 rather than 1 into 0.
   *   It is good practice to do all dedupe tests twice using this reversal to cover
   *   both scenarios.
   * @param bool $isAggressiveMode
   *   Should aggressive mode be used.
   *
   * @return array|int
   */
  protected function doMerge(bool $isReverse, bool $isAggressiveMode = FALSE) {
    $toKeepContactID = $isReverse ? $this->ids['Contact'][1] : $this->ids['Contact'][0];
    $toDeleteContactID = $isReverse ? $this->ids['Contact'][0] : $this->ids['Contact'][1];
    $mergeResult = $this->callAPISuccess('Contact', 'merge', ['to_keep_id' => $toKeepContactID, 'to_remove_id' => $toDeleteContactID, 'mode' => ($isAggressiveMode ? 'aggressive' : 'safe')])['values'];
    $mergedContact = $this->callAPISuccessGetSingle('Contact', ['id' => $toKeepContactID]);
    $this->assertCount(1, $mergeResult['merged']);
    return $mergedContact;
  }

  /**
   * Action to batch merge contacts.
   *
   * @param int $toKeepContactID
   *   ID of contact to be kept.
   * @param array $expected
   * @param bool $isAggressiveMode
   *   Should aggressive mode be used.
   *
   * @return array|int
   */
  protected function doBatchMerge(int $toKeepContactID, array $expected = [], bool $isAggressiveMode = FALSE) {
    $mergeResult = $this->callAPISuccess('Job', 'process_batch_merge', ['mode' => ($isAggressiveMode ? 'aggressive' : 'safe')])['values'];
    $mergedContact = $this->callAPISuccessGetSingle('Contact', ['id' => $toKeepContactID]);
    foreach ($expected as $key => $value) {
      $this->assertCount($value, $mergeResult[$key], $key . print_r($mergeResult, TRUE));
    }
    return $mergedContact;
  }

  /**
   * Create 2 donor contacts, differing in their source value.
   *
   * The first donor ($this->ids['Contact'][0] is the more recent donor.
   *
   * @param array $overrides
   */
  protected function createDuplicateDonors(array $overrides = [['source' => 'keep me'], ['source' => 'ditch me']]): void {
    $this->createDuplicateIndividuals($overrides);
    $receiveDate = '2017-08-09';
    foreach ($this->ids['Contact'] as $contactID) {
      $this->createTestEntity('Contribution', ['financial_type_id:name' => 'Donation', 'total_amount' => 5, 'contact_id' => $contactID, 'receive_date' => $receiveDate]);
      $receiveDate = '2016-08-09';
    }
  }

}

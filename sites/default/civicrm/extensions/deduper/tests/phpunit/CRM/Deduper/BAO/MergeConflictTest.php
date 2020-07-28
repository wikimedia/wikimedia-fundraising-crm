<?php

use CRM_Deduper_ExtensionUtil as E;
use Civi\Test\HeadlessInterface;
use Civi\Test\HookInterface;
use Civi\Test\TransactionalInterface;
use Civi\Test\Api3TestTrait;
use Civi\Api4\Email;

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

  /**
   * Setup for class.
   *
   * @throws \CRM_Core_Exception
   */
  public function setUp() {
    parent::setUp();
    $this->callAPISuccess('Setting', 'create', [
      'deduper_resolver_bool_prefer_yes' => ['on_hold', 'do_not_email', 'do_not_phone', 'do_not_mail', 'do_not_sms', 'do_not_trade', 'is_opt_out'],
    ]);
    // Make sure we don't have any lingering batch-mergeable contacts in the db.
    $this->callAPISuccess('Job', 'process_batch_merge', ['mode' => 'safe']);
  }

  /**
   * Test the boolean resolver works.
   *
   * @throws \CiviCRM_API3_Exception
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
   * @throws \CiviCRM_API3_Exception
   */
  public function testGetContactFields() {
    $fields = CRM_Deduper_BAO_MergeConflict::getContactFields();
    $this->assertTrue(isset($fields['contact_source']));
    $this->assertFalse(isset($fields['source'], $fields['street_address']));
  }

  /**
   * Test that a boolean field is resolved if set.
   *
   * @throws \CRM_Core_Exception
   */
  public function testResolveBooleanFields() {
    $this->createDuplicateIndividuals([['do_not_mail' => 0], ['do_not_mail' => 1]]);
    $this->callAPISuccess('Contact', 'merge', ['to_keep_id' => $this->ids['contact'][0], 'to_remove_id' => $this->ids['contact'][1]]);
    $mergedContacts = $this->callAPISuccess('Contact', 'get', ['id' => ['IN' => $this->ids['contact']]])['values'];

    $this->assertEquals(1, $mergedContacts[$this->ids['contact'][1]]['contact_is_deleted']);
    $this->assertEquals(0, $mergedContacts[$this->ids['contact'][0]]['contact_is_deleted']);
    $this->assertEquals(1, $mergedContacts[$this->ids['contact'][0]]['do_not_mail']);

    // Now try merging a contact with 0 in that field into our retained contact.
    $this->ids['contact'][2] = $this->callAPISuccess('Contact', 'create', ['first_name' => 'bob', 'do_not_mail' => 0, 'contact_type' => 'Individual'])['id'];
    $this->callAPISuccess('Contact', 'merge', ['to_keep_id' => $this->ids['contact'][0], 'to_remove_id' => $this->ids['contact'][2]]);
    $mergedContacts = $this->callAPISuccess('Contact', 'get', ['id' => ['IN' => $this->ids['contact'], 'is_deleted' => 0]])['values'];

    $this->assertEquals(1, $mergedContacts[$this->ids['contact'][0]]['do_not_mail']);

    $this->assertEquals(1, $mergedContacts[$this->ids['contact'][2]]['contact_is_deleted']);
    $this->assertEquals(0, $mergedContacts[$this->ids['contact'][0]]['contact_is_deleted']);
  }

  /**
   * Test that in aggressive mode we keep the values of the most preferred contact.
   *
   * We use most recently created contact as our resolver as that is the opposite to the default
   * behaviour without our hook.
   *
   * @param bool $isReverse
   *   Should we reverse which contact we merge into.
   *
   * @dataProvider booleanDataProvider
   *
   * @throws \CRM_Core_Exception
   */
  public function testResolveAggressivePreferredContact($isReverse) {
    $this->callAPISuccess('Setting', 'create', ['deduper_resolver_field_prefer_preferred_contact' => ['contact_source']]);
    $this->callAPISuccess('Setting', 'create', ['deduper_resolver_preferred_contact_resolution' => ['most_recently_created_contact']]);
    $this->createDuplicateDonors([['first_name' => 'Sally'], []]);
    $mergedContact = $this->doMerge($isReverse, TRUE);
    $this->assertEquals('Bob', $mergedContact['first_name']);
  }

  /**
   * Test that in safe mode we do not merge unresolved conflicts.
   *
   * @param bool $isReverse
   *   Should we reverse which contact we merge into.
   *
   * @dataProvider booleanDataProvider
   *
   * @throws \CRM_Core_Exception
   */
  public function testSafeModeDoesNotOverrideConflict($isReverse) {
    $this->callAPISuccess('Setting', 'create', ['deduper_resolver_field_prefer_preferred_contact' => ['contact_source']]);
    $this->callAPISuccess('Setting', 'create', ['deduper_resolver_preferred_contact_resolution' => ['most_recently_created_contact']]);
    $this->createDuplicateDonors([['first_name' => 'Sally'], []]);
    $this->doNotDoMerge($isReverse);
  }

  /**
   * Test the boolean field resolver resolves emails on hold.
   *
   * @param bool $isReverse
   *   Should we reverse which contact has on_hold set to true.
   *
   * @dataProvider booleanDataProvider
   *
   * @throws \CRM_Core_Exception
   */
  public function testResolveEmailOnHold($isReverse) {
    $this->createDuplicateIndividuals();
    // Conveniently our 2 contacts are 0 & 1 in the $this->ids['contact'] array so we can abuse the boolean var like this.
    $contactIDOnHold = $isReverse;

    $email1 = $this->callAPISuccess('Email', 'get', ['contact_id' => $this->ids['contact'][$contactIDOnHold]])['id'];
    $this->callAPISuccess('Email', 'create', ['id' => $email1, 'on_hold' => 1]);

    $mergeResult = $this->callAPISuccess('Contact', 'merge', ['to_keep_id' => $this->ids['contact'][0], 'to_remove_id' => $this->ids['contact'][1]])['values'];
    $this->assertCount(1, $mergeResult['merged']);
    $email0 = $this->callAPISuccessGetSingle('Email', ['contact_id' => ['IN' => [$this->ids['contact'][0]]]]);
    $this->assertEquals(1, $email0['on_hold']);
  }

  /**
   * Data provider for tests with 2 options
   *
   * @return array
   */
  public function booleanDataProvider(): array {
    return [[0], [1]];
  }

  /**
   * Test resolving an initial in the first name.
   *
   * @param bool $isReverse
   *   Should we reverse which contact we merge into.
   *
   * @dataProvider booleanDataProvider
   *
   * @throws \CRM_Core_Exception
   */
  public function testInitialResolution($isReverse) {
    $this->createDuplicateIndividuals([['first_name' => 'Bob M'], []]);
    $mergedContact = $this->doMerge($isReverse);
    $this->assertEquals('Bob', $mergedContact['first_name']);
    $this->assertEquals('M', $mergedContact['middle_name']);
  }

  /**
   * Test resolving an initial in the last name.
   *
   * @param bool $isReverse
   *   Should we reverse which contact we merge into.
   *
   * @dataProvider booleanDataProvider
   *
   * @throws \CRM_Core_Exception
   */
  public function testInitialResolutionInLast($isReverse) {
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
   *   Should we reverse which contact we merge into.
   *
   * @dataProvider booleanDataProvider
   *
   * @throws \CRM_Core_Exception
   */
  public function testInitialResolutionInLastWhenMiddleInitialInOther($isReverse) {
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
   *   Should we reverse which contact we merge into.
   *
   * @dataProvider booleanDataProvider
   *
   * @throws \CRM_Core_Exception
   */
  public function testInitialResolutionNameIsInitial($isReverse) {
    $this->createDuplicateIndividuals([['last_name' => 'S', 'first_name' => 'B'], []]);
    $mergedContact = $this->doMerge($isReverse);
    $this->assertEquals('Bob', $mergedContact['first_name']);
    $this->assertEquals('Smith', $mergedContact['last_name']);
  }

  /**
   * Test resolving an initial in the first name when the other contact already has the same value as an initial
   *
   * @param bool $isReverse
   *   Should we reverse which contact we merge into.
   *
   * @dataProvider booleanDataProvider
   *
   * @throws \CRM_Core_Exception
   */
  public function testInitialResolutionNameInitialExists($isReverse) {
    $this->createDuplicateIndividuals([['first_name' => 'Bob J'], ['middle_name' => 'J']]);
    $mergedContact = $this->doMerge($isReverse);
    $this->assertEquals('Bob', $mergedContact['first_name']);
    $this->assertEquals('Smith', $mergedContact['last_name']);
    $this->assertEquals('J', $mergedContact['middle_name']);
  }

  /**
   * Test resolving an initial in the first name when the other contact already has the same value as an initial with a dot.
   *
   * ie. [first_name => 'Bob J'] vs ['first_name' => 'Bob', 'middle_name' => 'J.']
   *
   * @param bool $isReverse
   *   Should we reverse which contact we merge into.
   *
   * @dataProvider booleanDataProvider
   *
   * @throws \CRM_Core_Exception
   */
  public function testInitialResolutionNameInitialExistsDotted($isReverse) {
    $this->createDuplicateIndividuals([['first_name' => 'Bob J.'], ['middle_name' => 'J.']]);
    $mergedContact = $this->doMerge($isReverse);
    $this->assertEquals('Bob', $mergedContact['first_name']);
    $this->assertEquals('Smith', $mergedContact['last_name']);
    $this->assertEquals('J', $mergedContact['middle_name']);
  }

  /**
   * Test that we don't allow silly names to create a conflict.
   *
   * Sometimes people enter cruft like 'first' for first_name. Later they
   * enter their real names. We can ignore known silly names when resolving conflicts.
   *
   * @param bool $isReverse
   *   Should we reverse which contact we merge into.
   *
   * @dataProvider booleanDataProvider
   *
   * @throws \CRM_Core_Exception
   */
  public function testIgnoreSillyNames($isReverse) {
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
   *   Should we reverse which contact we merge into.
   *
   * @dataProvider booleanDataProvider
   *
   * @throws \CRM_Core_Exception
   */
  public function testMergeMisspeltContacts($isReverse) {
    $this->createDuplicateIndividuals([['first_name' => 'Benjamin'], ['first_name' => 'Benjamain']]);
    $mergedContact = $this->doMerge($isReverse);
    $this->assertEquals('Benjamin', $mergedContact['first_name']);
  }

  /**
   * Test that contacts can be resolved to the nick name, setting dependent.
   *
   * @param bool $isReverse
   *   Should we reverse which contact we merge into.
   *
   * @dataProvider booleanDataProvider
   *
   * @throws \CRM_Core_Exception
   */
  public function testMergePreferNickName($isReverse) {
    $this->callAPISuccess('Setting', 'create', ['deduper_equivalent_name_handling' => 'prefer_nick_name']);
    $this->createDuplicateIndividuals([['first_name' => 'Theodore'], ['first_name' => 'Ted']]);
    $mergedContact = $this->doMerge($isReverse);
    $this->assertEquals('Ted', $mergedContact['first_name']);
  }

  /**
   * Test that contacts can be resolved to the non nick name, setting dependent.
   *
   * @param bool $isReverse
   *   Should we reverse which contact we merge into.
   *
   * @dataProvider booleanDataProvider
   *
   * @throws \CRM_Core_Exception
   */
  public function testMergePreferNonNickName($isReverse) {
    $this->callAPISuccess('Setting', 'create', ['deduper_equivalent_name_handling' => 'prefer_non_nick_name']);
    $this->createDuplicateIndividuals([['first_name' => 'Theodore'], ['first_name' => 'Ted']]);
    $mergedContact = $this->doMerge($isReverse);
    $this->assertEquals('Theodore', $mergedContact['first_name']);
  }

  /**
   * Test that contacts can be resolved to the non nick name, setting dependent.
   *
   * @param bool $isReverse
   *   Should we reverse which contact we merge into.
   *
   * @dataProvider booleanDataProvider
   *
   * @throws \CRM_Core_Exception
   */
  public function testMergePreferNonNickNameKeepNickName($isReverse) {
    $this->callAPISuccess('Setting', 'create', ['deduper_equivalent_name_handling' => 'prefer_non_nick_name_keep_nick_name']);
    $this->createDuplicateIndividuals([['first_name' => 'Theodore'], ['first_name' => 'Ted']]);
    $mergedContact = $this->doMerge($isReverse);
    $this->assertEquals('Theodore', $mergedContact['first_name']);
    $this->assertEquals('Ted', $mergedContact['nick_name'], 'Nick name should be set');
  }

  /**
   * Test that contacts can be resolved to the non nick name, setting dependent.
   *
   * @param bool $isReverse
   *   Should we reverse which contact we merge into.
   *
   * @dataProvider booleanDataProvider
   *
   * @throws \CRM_Core_Exception
   */
  public function testMergePreferredContactNonNickNameKeepNickName($isReverse) {
    $this->callAPISuccess('Setting', 'create', ['deduper_equivalent_name_handling' => 'prefer_preferred_contact_value_keep_nick_name']);
    $this->callAPISuccess('Setting', 'create', ['deduper_resolver_preferred_contact_resolution' => ['most_recent_contributor']]);
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
   *   Should we reverse which contact we merge into.
   *
   * @dataProvider booleanDataProvider
   *
   * @throws \CRM_Core_Exception
   */
  public function testMisplacedNameResolutionFullNameInFirstName($isReverse) {
    $this->createDuplicateIndividuals([['last_name' => 'null', 'first_name' => 'Bob M Smith'], []]);
    $mergedContact = $this->doMerge($isReverse);
    $this->assertEquals('Bob', $mergedContact['first_name']);
    $this->assertEquals('Smith', $mergedContact['last_name']);
    $this->assertEquals('M', $mergedContact['middle_name']);
  }

  /**
   * Test resolving an initial in the first name.
   *
   * @param bool $isReverse
   *   Should we reverse which contact we merge into.
   *
   * @param array $data
   *
   * @throws \CRM_Core_Exception
   * @dataProvider mergeConflictProvider
   *
   */
  public function testMisplacedNameResolutions($isReverse, $data) {
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
      'contact_2' => ['middle_name' => 'null'],
      'expected' => ['middle_name' => 'M J'],
    ];
    $dataset[] = [
      'contact_1' => ['first_name' => 'null', 'last_name' => 'Bob M Smith'],
      'contact_2' => [],
    ];
    $dataset[] = [
      'contact_1' => ['first_name' => 'null', 'last_name' => 'Bob M Smith'],
      'contact_2' => ['middle_name' => 'M'],
    ];

    $return = [];
    $expected = ['first_name' => 'Bob', 'middle_name' => 'M', 'last_name' => 'Smith'];
    foreach ($dataset as $data) {
      $data['expected'] = array_merge($expected, $data['expected'] ?? []);
      $return[] = [0, $data];
      $return[] = [1, $data];
    }
    return $return;
  }

  /**
   * Test resolving an initial in the first name.
   *
   * @param bool $isReverse
   *   Should we reverse which contact we merge into.
   *
   * @dataProvider booleanDataProvider
   *
   * @throws \CRM_Core_Exception
   */
  public function testMisplacedNameResolutionFullRepeatedInLastName($isReverse) {
    $this->createDuplicateIndividuals([['first_name' => 'Bob', 'last_name' => 'Bob Max Smith'], ['last_name' => 'Max Smith']]);
    $mergedContact = $this->doMerge($isReverse);
    $this->assertEquals('Bob', $mergedContact['first_name']);
    $this->assertEquals('Max Smith', $mergedContact['last_name']);
  }

  /**
   * Test resolving an initial in the first name with punctuation.
   *
   * @param bool $isReverse
   *   Should we reverse which contact we merge into.
   *
   * @dataProvider booleanDataProvider
   *
   * @throws \CRM_Core_Exception
   */
  public function testMisplacedNameResolutionWithPunctuation($isReverse) {
    $this->createDuplicateIndividuals([['first_name' => 'null', 'last_name' => 'Bob M. Smith'], []]);
    $mergedContact = $this->doMerge($isReverse);
    $this->assertEquals('Bob', $mergedContact['first_name']);
    $this->assertEquals('Smith', $mergedContact['last_name']);
    $this->assertEquals('M', $mergedContact['middle_name']);
  }

  /**
   * Test that a name field that is the same apart from white space can be resolved.
   *
   * @param bool $isReverse
   *   Should we reverse which contact we merge into.
   *
   * @dataProvider booleanDataProvider
   *
   * @throws \CRM_Core_Exception
   */
  public function testResolveWhiteSpaceInName($isReverse) {
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
   *   Should we reverse which contact we merge into.
   *
   * @dataProvider booleanDataProvider
   *
   * @throws \CRM_Core_Exception
   */
  public function testResolvePreferredContactField($isReverse) {
    $this->callAPISuccess('Setting', 'create', ['deduper_resolver_field_prefer_preferred_contact' => ['contact_source']]);
    $this->callAPISuccess('Setting', 'create', ['deduper_resolver_preferred_contact_resolution' => ['earliest_created_contact']]);
    $this->createDuplicateIndividuals([['contact_source' => 'keep me'], ['contact_source' => 'ditch me']]);
    $mergedContact = $this->doMerge($isReverse);
    $this->assertEquals('keep me', $this->callAPISuccessGetValue('Contact', ['return' => 'contact_source', 'id' => $mergedContact['id']]));
  }

  /**
   * Test resolving a field where we resolve by preferred contact.
   *
   * Use most recently created contact resolver.
   *
   * @param bool $isReverse
   *   Should we reverse which contact we merge into.
   *
   * @dataProvider booleanDataProvider
   *
   * @throws \CRM_Core_Exception
   */
  public function testResolvePreferredContactFieldChooseLatest($isReverse) {
    $this->callAPISuccess('Setting', 'create', ['deduper_resolver_field_prefer_preferred_contact' => ['contact_source']]);
    $this->callAPISuccess('Setting', 'create', ['deduper_resolver_preferred_contact_resolution' => ['most_recently_created_contact']]);
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
 *   Should we reverse which contact we merge into.
 *
 * @dataProvider booleanDataProvider
 *
 * @throws \CRM_Core_Exception
 */
  public function testResolvePreferredContactFieldChooseMostRecentDonor($isReverse) {
    $this->callAPISuccess('Setting', 'create', ['deduper_resolver_field_prefer_preferred_contact' => ['contact_source']]);
    $this->callAPISuccess('Setting', 'create', ['deduper_resolver_preferred_contact_resolution' => ['most_recent_contributor']]);
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
   *   Should we reverse which contact we merge into.
   *
   * @dataProvider booleanDataProvider
   *
   * @throws \CRM_Core_Exception
   */
  public function testResolvePreferredContactFieldChooseMostProlific($isReverse) {
    $this->callAPISuccess('Setting', 'create', ['deduper_resolver_field_prefer_preferred_contact' => ['contact_source']]);
    $this->callAPISuccess('Setting', 'create', ['deduper_resolver_preferred_contact_resolution' => ['most_prolific_contributor']]);
    $this->createDuplicateDonors();
    // Add a second contribution to the first donor - making it more prolific.
    $this->callAPISuccess('Contribution', 'create', ['financial_type_id' => 'Donation', 'total_amount' => 5, 'contact_id' => $this->ids['contact'][0], 'receive_date' => '2019-08-08']);
    $mergedContact = $this->doMerge($isReverse);
    $this->assertEquals('keep me', $this->callAPISuccessGetValue('Contact', ['return' => 'contact_source', 'id' => $mergedContact['id']]));
  }

  /**
   * Test that we don't treat the addition of a postal suffix only as a conflict.
   *
   * Bug T177807
   *
   * @param bool $isReverse
   *   Should we reverse which contact we merge into.
   *
   * @dataProvider booleanDataProvider
   *
   * @throws \CRM_Core_Exception
   */
  public function testBatchMergeResolvableConflictPostalSuffixExists($isReverse) {
    $this->createDuplicateDonors();
    $contactIDWithPostalSuffix = ($isReverse ? $this->ids['contact'][1] : $this->ids['contact'][0]);
    $contactIDWithOutPostalSuffix = ($isReverse ? $this->ids['contact'][0] : $this->ids['contact'][1]);
    $this->callAPISuccess('Address', 'create', [
      'country_id' => 'MX',
      'contact_id' => $contactIDWithPostalSuffix,
      'location_type_id' => 1,
      'street_address' => 'First on the left after you cross the border',
      'postal_code' => 90210,
      'postal_code_suffix' => 6666,
    ]);
    $this->callAPISuccess('Address', 'create', [
      'country_id' => 'MX',
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
   * Test that a conflict on casing in first names is handled.
   *
   * We do a best effort on this to get the more correct on assuming that 1 capital letter in a
   * name is most likely to be deliberate. We prioritise less capital letters over more, except that
   * all lower case is at the end of the queue.
   *
   * This won't necessarily give us the best results for 'La Plante' vs 'la Plante' but we should bear in mind
   * - both variants have been entered by the user at some point so they have not 'chosen' one.
   * - having 2 variants of the spelling of a name with more than one upper case letter in our
   * db is an edge case.
   *
   * @throws \CRM_Core_Exception
   */
  public function testCasingConflicts() {
    $this->createDuplicateIndividuals([
      ['first_name' => 'donald', 'last_name' => 'Duck'],
      ['first_name' => 'Donald', 'last_name' => 'duck'],
      ['first_name' => 'DONALD', 'last_name' => 'DUCK'],
      ['first_name' => 'DonalD', 'last_name' => 'DUck'],
    ]);
    $mergedContact = $this->doBatchMerge($this->ids['contact'][0], ['skipped' => 0, 'merged' => 3]);
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
   * @param string $preferredName
   *
   * @dataProvider getDiacriticData
   *
   * @throws \CRM_Core_Exception
   */
  public function testDiacriticConflicts($names, $isMatch, $preferredName) {
    $this->createDuplicateIndividuals([
      ['first_name' => $names[0]],
      ['first_name' => $names[1]],
    ]);
    $mergedContact = $this->doBatchMerge($this->ids['contact'][0], ['skipped' => (int) !$isMatch, 'merged' => (int) $isMatch]);
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
    $dataSet['accent_vs_no_accent_revese'] = [
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
   *
   * @throws \CRM_Core_Exception
   */
  private function createDuplicateIndividuals($contactParams = [[], []]) {
    $params = [
      'first_name' => 'Bob',
      'last_name' => 'Smith',
      'contact_type' => 'Individual',
      'email' => 'bob@example.com',
    ];
    foreach ($contactParams as $index => $contactParam) {
      $contactParam = array_merge($params, $contactParam);
      $this->ids['contact'][$index] = $this->callAPISuccess('Contact', 'create', $contactParam)['id'];
    }
  }

  /**
   * Action the merge of the 2 created contacts.
   *
   * @param bool $isReverse
   *   Is the order to be reversed - ie. merge contact 0 into 1 rather than 1 into 0.
   *   It is good practice to do all dedupe tests twice using this reversal to cover
   *   both scenarios.
   *
   * @throws \CRM_Core_Exception
   */
  protected function doNotDoMerge($isReverse) {
    $toKeepContactID = $isReverse ? $this->ids['contact'][1] : $this->ids['contact'][0];
    $toDeleteContactID = $isReverse ? $this->ids['contact'][0] : $this->ids['contact'][1];
    $mergeResult = $this->callAPISuccess('Contact', 'merge', ['to_keep_id' => $toKeepContactID, 'to_remove_id' => $toDeleteContactID])['values'];
    $this->assertCount(1, $mergeResult['skipped']);
    $this->assertCount(0, $mergeResult['merged']);
  }

  /**
   * Action the merge of the 2 created contacts.
   *
   * @param bool $isReverse
   *   Is the order to be reversed - ie. merge contact 0 into 1 rather than 1 into 0.
   *   It is good practice to do all dedupe tests twice using this reversal to cover
   *   both scenarios.
   * @param bool $isAggressiveMode
   *   Should aggressive mode be used.
   *
   * @return array|int
   * @throws \CRM_Core_Exception
   */
  protected function doMerge($isReverse, $isAggressiveMode = FALSE) {
    $toKeepContactID = $isReverse ? $this->ids['contact'][1] : $this->ids['contact'][0];
    $toDeleteContactID = $isReverse ? $this->ids['contact'][0] : $this->ids['contact'][1];
    $mergeResult = $this->callAPISuccess('Contact', 'merge', ['to_keep_id' => $toKeepContactID, 'to_remove_id' => $toDeleteContactID, 'mode' => ($isAggressiveMode ? 'aggressive' : 'safe')])['values'];
    $mergedContact = $this->callAPISuccessGetSingle('Contact', ['id' => $toKeepContactID]);
    $this->assertCount(1, $mergeResult['merged']);
    return $mergedContact;
  }

  /**
   * Action to batch merge contacts.
   *
   * @param int $toKeepContactID
   *   Id of contact to be kept.
   * @param array $expected
   * @param bool $isAggressiveMode
   *   Should aggressive mode be used.
   *
   * @return array|int
   * @throws \CRM_Core_Exception
   */
  protected function doBatchMerge($toKeepContactID, $expected = [], $isAggressiveMode = FALSE) {
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
   * The first donor ($this->ids['contact'][0] is the more recent donor.
   *
   * @param array $overrides
   *
   * @throws \CRM_Core_Exception
   */
  protected function createDuplicateDonors($overrides = [['contact_source' => 'keep me'], ['contact_source' => 'ditch me']]) {
    $this->createDuplicateIndividuals($overrides);
    $receiveDate = '2017-08-09';
    foreach ($this->ids['contact'] as $contactID) {
      $this->callAPISuccess('Contribution', 'create', ['financial_type_id' => 'Donation', 'total_amount' => 5, 'contact_id' => $contactID, 'receive_date' => $receiveDate]);
      $receiveDate = '2016-08-09';
    }
  }

}

<?php

namespace Civi\Wmf;

use Civi\Api4\Contact;
use Civi\Api4\Email;
use Civi\Test\HeadlessInterface;
use Civi\Test\HookInterface;
use Civi\Test\TransactionalInterface;

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
class MergeTest extends \PHPUnit\Framework\TestCase implements HeadlessInterface, HookInterface, TransactionalInterface {

  use \Civi\Test\Api3TestTrait;

  /**
   * Id of the contact created in the setup function.
   *
   * @var int
   */
  protected $contactID;

  /**
   * Id of the contact created in the setup function.
   *
   * @var int
   */
  protected $contactID2;

  /**
   * Logged in admin user id.
   *
   * @var int
   */
  protected $adminUserID;

  /**
   * @var int
   */
  protected $intitalContactCount;

  /**
   * @return \Civi\Test\CiviEnvBuilder
   * @throws \CRM_Extension_Exception_ParseException
   */
  public function setUpHeadless() {
    // Civi\Test has many helpers, like install(), uninstall(), sql(), and sqlFile().
    // See: https://docs.civicrm.org/dev/en/latest/testing/phpunit/#civitest
    return \Civi\Test::headless()
      ->installMe(__DIR__)
      ->apply();
  }

  /**
   * @throws \Exception
   */
  public function setUp() {
    parent::setUp();
    civicrm_initialize();
    $this->adminUserID = $this->imitateAdminUser();
    $this->intitalContactCount = $this->callAPISuccessGetCount('Contact', ['is_deleted' => '']);

    $this->contactID = $this->breedDuck([wmf_civicrm_get_custom_field_name('do_not_solicit') => 0]);
    $this->contactID2 = $this->breedDuck([wmf_civicrm_get_custom_field_name('do_not_solicit') => 1]);
    $locationTypes = array_flip(\CRM_Core_BAO_Address::buildOptions('location_type_id', 'validate'));
    $types = [];
    foreach (['Main', 'Other', 'Home', 'Mailing', 'Billing', 'Work'] as $type) {
      $types[] = $locationTypes[$type];
    }

    \Civi::settings()->set('deduper_location_priority_order', $types);
    \Civi::settings()->set('deduper_resolver_email', 'preferred_contact_with_re-assign');
    \Civi::settings()->set('deduper_resolver_field_prefer_preferred_contact', ['contact_source', wmf_civicrm_get_custom_field_name('opt_in')]);
    \Civi::settings()->set('deduper_resolver_preferred_contact_resolution', ['most_recent_contributor']);
    \Civi::settings()->set('deduper_resolver_preferred_contact_last_resort', 'most_recently_created_contact');
  }

  /**
   * Clean up after test.
   *
   * @throws \CRM_Core_Exception
   */
  public function tearDown() {
    $this->callAPISuccess('Contribution', 'get', [
      'contact_id' => ['IN' => [$this->contactID, $this->contactID2]],
      'api.Contribution.delete' => 1,
    ]);
    \CRM_Core_Session::singleton()->set('userID', NULL);
    $this->callAPISuccess('Contact', 'delete', ['id' => $this->contactID, 'skip_undelete' => TRUE]);
    $this->callAPISuccess('Contact', 'delete', ['id' => $this->contactID2, 'skip_undelete' => TRUE]);
    $this->doDuckHunt();
    parent::tearDown();
    $this->assertEquals($this->intitalContactCount, $this->callAPISuccessGetCount('Contact', ['is_deleted' => '']), 'contact cleanup incomplete');
  }

  /**
   * Test that the merge hook causes our custom fields to not be treated as conflicts.
   *
   * We also need to check the custom data fields afterwards.
   *
   * @param bool $isReverse
   *   Should we reverse the contact order for more test cover.
   *
   * @dataProvider isReverse
   * @throws \CRM_Core_Exception
   * @throws \API_Exception
   */
  public function testMergeHook($isReverse) {
    $this->giveADuckADonation($isReverse);
    $contact = $this->callAPISuccess('Contact', 'get', [
      'id' => $isReverse ? $this->contactID2 : $this->contactID,
      'sequential' => 1,
      'return' => [wmf_civicrm_get_custom_field_name('lifetime_usd_total'), wmf_civicrm_get_custom_field_name('do_not_solicit')],
    ])['values'][0];
    $this->assertEquals(10, $contact[wmf_civicrm_get_custom_field_name('lifetime_usd_total')],
      'logging is ' . \Civi::settings()->get('logging')
    );
    $result = $this->callAPISuccess('Job', 'process_batch_merge', [
      'criteria' => ['contact' => ['id' => ['IN' => [$this->contactID, $this->contactID2]]]],
    ]);
    $this->assertCount(1, $result['values']['merged']);
    $this->assertContactValues($this->contactID, [
      'wmf_donor.lifetime_usd_total' => 24,
      'Communication.do_not_solicit' => 1,
      'wmf_donor.last_donation_amount' => 20,
      'wmf_donor.last_donation_currency' => 'NZD',
      'wmf_donor.last_donation_usd' => 9,
      'wmf_donor.last_donation_date' => '2016-04-04 00:00:00',
      'wmf_donor.first_donation_usd' => 5,
      'wmf_donor.first_donation_date' => '2013-01-04 00:00:00',
      'wmf_donor.date_of_largest_donation' => '2014-08-04 00:00:00',
      'wmf_donor.number_donations' => 3,
      'wmf_donor.total_2011' => 0,
      'wmf_donor.total_2012' => 0,
      'wmf_donor.total_2013' => 5,
      'wmf_donor.total_2014' => 10,
      'wmf_donor.total_2015' => 0,
      'wmf_donor.total_2016' => 9,
      'wmf_donor.total_2016_2017' => 0,
      'wmf_donor.total_2015_2016' => 9,
      'wmf_donor.total_2014_2015' => 10,
      'wmf_donor.total_2013_2014' => 0,
      'wmf_donor.total_2012_2013' => 5,
    ]);

    // Now lets check the one to be deleted has a do_not_solicit = 0.
    $this->callAPISuccess('Contact', 'create', [
      'contact_type' => 'Individual',
      'first_name' => 'Donald',
      'last_name' => 'Duck',
      'email' => 'the_don@duckland.com',
      wmf_civicrm_get_custom_field_name('do_not_solicit') => 0,
    ]);
    $result = $this->callAPISuccess('Job', 'process_batch_merge', [
      'criteria' => ['contact' => ['id' => $this->contactID]],
    ]);
    $this->assertCount(1, $result['values']['merged']);
    $contact = $this->callAPISuccess('Contact', 'get', [
      'id' => $this->contactID,
      'sequential' => 1,
      'return' => [wmf_civicrm_get_custom_field_name('lifetime_usd_total'), wmf_civicrm_get_custom_field_name('do_not_solicit')],
    ]);
    $this->assertEquals(1, $contact['values'][0][wmf_civicrm_get_custom_field_name('do_not_solicit')]);
  }

  /**
   * Data provider for merge hook to do both ways around.
   *
   * @return array
   */
  public function isReverse(): array {
    return [
      [FALSE],
      [TRUE],
    ];
  }

  /**
   * Test the handling when the match is between a primary and a non-primary email.
   *
   * What I witnessed on live was a case where the higher contact id was a less recent donor,
   * and was merged into the more recent donor with the lower contact_id
   *
   * Before
   * | id       | email            | contact_id | location_type_id | is_primary | on_hold |
   * +----------+---------------------------+------------+------------------+------------+---------+
   * |  4995727 | 1@example.com    |      123   |                3 |          0 |       1 |
   * | 12970851 | 2@example.com    |      123   |                5 |          0 |       1 |
   * | 36741158 | 3@example.com    |      123   |                1 |          1 |       0 |
   * |  1068509 | 1@example.com    |      456   |                1 |          1 |       1 |
   *
   * After
   * | id       | email            | contact_id | location_type_id | is_primary | on_hold |
   * +----------+---------------------------+------------+------------------+------------+---------+
   * |  4995727 | 1@example.com    |      123   |                3 |          0 |       1 |
   * | 12970851 | 2@example.com    |      123   |                5 |          0 |       1 |
   *
   * What it should be
   * | id       | email            | contact_id | location_type_id | is_primary | on_hold |
   * +----------+---------------------------+------------+------------------+------------+---------+
   * |  4995727 | 1@example.com    |      123   |                3 |          0 |       1 |
   * | 12970851 | 2@example.com    |      123   |                5 |          0 |       1 |
   * | 36741158 | 3@example.com    |      123   |                1 |          1 |       0 |
   *
   * Note that the test only uses 2 emails as 2@example.com above seems extraneous to the issue
   *
   * @param bool $isReverse
   *   Should we reverse the contact order for more test cover.
   *
   * @dataProvider isReverse
   *
   * @throws \CRM_Core_Exception
   * @throws \API_Exception
   */
  public function testMergeEmailNonPrimary($isReverse) {
    $this->giveADuckADonation($isReverse);
    $moreRecentlyGenerousDuck = $isReverse ? $this->contactID : $this->contactID2;
     Email::replace()
      ->setCheckPermissions(FALSE)
      ->setRecords([
        ['email' => 'the_don@duckland.com', 'location_type_id:name' => 'Other'],
        ['email' => 'better_duck@duckland.com', 'is_primary' => TRUE, 'location_type_id:name' => 'Work'],
     ])
      ->setWhere([['contact_id', '=', $moreRecentlyGenerousDuck]])
      ->addDefault('contact_id', $moreRecentlyGenerousDuck)
      ->execute();
    $result = $this->callAPISuccess('Job', 'process_batch_merge', [
      'criteria' => ['contact' => ['id' => $this->contactID]],
    ])['values'];
    $this->assertCount(1, $result['merged']);
    $emails = Email::get()->addSelect('*')->addWhere('contact_id', '=', $this->contactID)->setOrderBy([ 'is_primary' => 'DESC'])->execute();
    $this->assertCount(2, $emails);
    $primary = $emails->first();
    $this->assertEquals('better_duck@duckland.com', $primary['email']);
    $this->assertEquals(TRUE, $primary['is_primary']);
    $this->assertOnePrimary($emails);
  }

  /**
   * Test that wmf_donor calculations don't include Endowment.
   *
   * We set up 2 contacts
   *  - one with an Endowment gift
   *  - one with a cash donation
   *
   * We check the cash donation but not the gift is in the totals
   *
   * After merging we check the same again.
   *
   * Although a bit tangental we test calcs on deleting a contribution at the end.
   *
   * @throws \CRM_Core_Exception
   * @throws \API_Exception
   */
  public function testMergeEndowmentCalculation() {
    $this->callAPISuccess('Contribution', 'create', [
      'contact_id' => $this->contactID,
      'financial_type_id' => 'Endowment Gift',
      'total_amount' => 10,
      'currency' => 'USD',
      'receive_date' => '2014-08-04',
      wmf_civicrm_get_custom_field_name('original_currency') => 'NZD',
      wmf_civicrm_get_custom_field_name('original_amount') => 8,
    ]);
    $cashJob = $this->callAPISuccess('Contribution', 'create', [
      'contact_id' => $this->contactID2,
      'financial_type_id' => 'Cash',
      'total_amount' => 5,
      'currency' => 'USD',
      'receive_date' => '2013-01-04',
    ]);

    $this->callAPISuccess('Contribution', 'create', [
      'contact_id' => $this->contactID2,
      'financial_type_id' => 'Endowment Gift',
      'total_amount' => 7,
      'currency' => 'USD',
      'receive_date' => '2015-01-04',
    ]);

    $contact = $this->callAPISuccess('Contact', 'get', [
      'id' => $this->contactID,
      'sequential' => 1,
      'return' => [wmf_civicrm_get_custom_field_name('lifetime_usd_total')],
    ])['values'][0];

    $this->assertEquals(0, $contact[wmf_civicrm_get_custom_field_name('lifetime_usd_total')]);

    $contact = $this->callAPISuccess('Contact', 'get', [
      'id' => $this->contactID2,
      'sequential' => 1,
      'return' => [wmf_civicrm_get_custom_field_name('lifetime_usd_total')],
    ])['values'][0];
    $this->assertEquals(5, $contact[wmf_civicrm_get_custom_field_name('lifetime_usd_total')]);

    $result = $this->callAPISuccess('Job', 'process_batch_merge', [
      'criteria' => ['contact' => ['id' => ['IN' => [$this->contactID, $this->contactID2]]]],
    ]);
    $this->assertCount(1, $result['values']['merged']);
    $this->assertContactValues($this->contactID, [
      'wmf_donor.lifetime_usd_total' => 5,
      'wmf_donor.last_donation_amount' => 5,
      'wmf_donor.last_donation_currency' => 'USD',
      'wmf_donor.last_donation_usd' => 5,
      'wmf_donor.last_donation_date' => '2013-01-04 00:00:00',
      'wmf_donor.total_2016_2017' => 0,
      'wmf_donor.total_2015_2016' => 0,
      'wmf_donor.total_2014_2015' => 0,
      'wmf_donor.total_2013_2014' => 0,
      'wmf_donor.total_2012_2013' => 5,
    ]);

    $this->callAPISuccess('Contribution', 'delete', ['id' => $cashJob['id']]);
    $this->assertContactValues($this->contactID, [
      'wmf_donor.lifetime_usd_total' => 0,
      'wmf_donor.last_donation_amount' => 0,
      'wmf_donor.last_donation_currency' => '',
      'wmf_donor.last_donation_usd' => 0,
      'wmf_donor.last_donation_date' => '',
      'wmf_donor.total_2016_2017' => 0,
      'wmf_donor.total_2015_2016' => 0,
      'wmf_donor.total_2014_2015' => 0,
      'wmf_donor.total_2013_2014' => 0,
      'wmf_donor.total_2012_2013' => 0,
    ]);
  }

  /**
   * Test altering the address decision by hook.
   *
   * I feel I did something a bit sneaky here. I actually wrote both the test and
   * the hook against the core repo and committed in in this test.
   *
   * I figured that made core more robust & helped future proof us.
   *
   * https://github.com/civicrm/civicrm-core/blob/master/tests/phpunit/api/v3/JobTest.php#L584
   * https://github.com/civicrm/civicrm-core/blob/master/tests/phpunit/api/v3/JobTest.php#L643
   *
   * However, I'm replicating the test into our repo to test it still works distilled
   * into our hook.
   *
   * Tested scenarios: Note these apply to addresses, phones & emails.
   *
   *  1) (Fill data) both contacts have the same primary with the same location (Home). The first has an additional address (Mailing).
   *      Outcome: common primary is retained as the Home address & additional Mailing address is retained on the merged contact.
   *      Notes: our behaviour is the same as core.
   *  2) (Fill data) (reverse of 1) both contacts have the same primary with the same location (Home).
   *      The second has an additional  address (Mailing).
   *      Outcome: common primary is retained & additional Mailing address is retained on the merged contact.
   *      Notes: our behaviour is the same as core.
   *  3) (Fill data)  only one contact has an address (Home) - first contact.
   *     Outcome: address retained
   *     Notes: our behaviour is the same as core.
   *  4) (Fill data) (reverse of 3) only one contact has an address (Home) - second contact.
   *     Outcome: address retained
   *     Notes: our behaviour is the same as core.
   *  5) (Resolve Data) Contacts have different primary addresses with different
   *     location types. ie. first has a primary Home address. Second has a primary
   *     Mailing address. Addresses Differ.
   *     Outcome: keep both addresses. Use the address of the later donor as the primary.
   *     Notes: differs from core behaviour which would keep the address of the contact
   *     with the lower contact_id as the primary
   *  6) (Resolve Data) (reverse of 5) Contacts have different primary addresses with different
   *     location types. ie. first has a primary Mailing address.  Second has a primary
   *     Home address. Addresses Differ.
   *     Outcome: keep both addresses. Use the address of the later donor as the primary.
   *     Notes: differs from core behaviour which would keep the address of the contact
   *     with the lower contact_id as the primary
   *  7) (Resolve Data) Contacts have the same Home address. For the first the Home
   *     address is primary. For the second a (different) mailing address is.
   *     Outcome: both addresses kept. The one that is primary for the later donor is primary.
   *     Notes: same as 5 & 6 but with an additional address. Differs from core which
   *     would set primary to match to lower contact_id.
   *  8) (Resolve Data) (reverse of 7) Contacts have the same Mailing address. For the first
   *     the Mailing address is primary. For the second a (different) home address is.
   *     Outcome: both addresses kept. The one that is primary for the later donor is primary.
   *     Notes: same as 5 & 6 but with an additional address. Differs from core which
   *     would set primary to match to lower contact_id.
   *  9) (Resolve Data) Contacts have the same primary address but for the first
   *     contact is is Home whereas for the second is is Mailing.
   *     Outcome: keep the address. Use the Mailing location of the later donor (the second).
   *     Notes: differs from core behaviour which would keep 2 copies of the address with
   *     2 locations.
   * 10) (Resolve Data) (reverse of 9) Contacts have the same primary address but for the first
   *     contact is is Mailing whereas for the second is is Home.
   *     Outcome: keep the address. Use the Home location of the later donor (the second).
   *     Notes: differs from core behaviour which would keep 2 copies of the address with
   *     2 locations.
   * 11) (Throw conflict) Contacts have conflicting home address. Total giving = $500.
   *     Outcome: conflict - do not merge.
   *     Notes: This is like core, but for us less than 500 will merge.
   * 12) (Resolve Data)  Contacts have conflicting home address. Total giving < $500.
   *     Outcome: merge - only keep home address of latest donor.
   *     Notes: differs from core.
   * 13) (Throw conflict) Contacts have conflicting home address and matching primary (Mailing). Total giving = $500.
   *     Outcome: conflict - do not merge.
   *     Notes: This is like core, but for us less than 500 will merge.
   * 14) (Resolve Data)  Contacts have conflicting home address. Total giving < $500.
   *     Outcome: merge - only keep home address of latest donor. Keep Mailing.
   *     Notes: differs from core.
   *
   * @dataProvider getMergeLocationData
   *
   * @param array $dataSet
   *
   * @throws \CRM_Core_Exception
   */
  public function testBatchMergesAddressesHook($dataSet) {
    $this->contributionCreate(['contact_id' => $this->contactID, 'receive_date' => '2010-01-01', 'invoice_id' => 1, 'trxn_id' => 1]);
    $this->contributionCreate(['contact_id' => $this->contactID2, 'receive_date' => '2012-01-01', 'invoice_id' => 2, 'trxn_id' => 2]);
    if ($dataSet['is_major_gifts']) {
      $this->contributionCreate(['contact_id' => $this->contactID2, 'receive_date' => '2012-01-01', 'total_amount' => 300]);
    }
    foreach ($dataSet['earliest_donor'] as $address) {
      $this->callAPISuccess($dataSet['entity'], 'create', array_merge(['contact_id' => $this->contactID], $address));
    }
    foreach ($dataSet['most_recent_donor'] as $address) {
      $this->callAPISuccess($dataSet['entity'], 'create', array_merge(['contact_id' => $this->contactID2], $address));
    }

    $result = $this->callAPISuccess('Job', 'process_batch_merge', ['mode' => 'safe']);

    $this->assertCount($dataSet['skipped'], $result['values']['skipped']);
    $this->assertCount($dataSet['merged'], $result['values']['merged']);
    $addresses = $this->callAPISuccess($dataSet['entity'], 'get', ['contact_id' => $this->contactID, 'sequential' => 1])['values'];
    $this->assertCount(count($dataSet['expected_hook']), $addresses);
    $locationTypes = $this->callAPISuccess($dataSet['entity'], 'getoptions', ['field' => 'location_type_id'])['values'];
    foreach ($dataSet['expected_hook'] as $index => $expectedAddress) {
      foreach ($expectedAddress as $key => $value) {
        if ($key === 'location_type_id') {
          $this->assertEquals($locationTypes[$addresses[$index][$key]], $value);
        }
        else {
          $this->assertEquals($value, $addresses[$index][$key], $dataSet['entity'] . ': Unexpected value for ' . $key . (!empty($dataSet['description']) ? " on dataset {$dataSet['description']}" : ''));
        }
      }
    }
    $this->assertOnePrimary($addresses);
  }

  /**
   * Do address tests with contact ids reversed.
   *
   * Since the higher ID merges into the lower ID we were seeing accidental successes despite an error.
   *
   * This reversal of the previous test set forces it to work on logic not id co-incidence.
   *
   * @dataProvider getMergeLocationData
   *
   * @param array $dataSet
   *
   * @throws \CRM_Core_Exception
   */
  public function testBatchMergesAddressesHookLowerIDMoreRecentDonor($dataSet) {
    // here the lower contact ID has the higher receive_date as opposed to the previous test.
    $this->contributionCreate(['contact_id' => $this->contactID2, 'receive_date' => '2010-01-01', 'invoice_id' => 1, 'trxn_id' => 1]);
    $this->contributionCreate(['contact_id' => $this->contactID, 'receive_date' => '2012-01-01', 'invoice_id' => 2, 'trxn_id' => 2]);
    if ($dataSet['is_major_gifts']) {
      $this->contributionCreate(['contact_id' => $this->contactID, 'receive_date' => '2012-01-01', 'total_amount' => 300]);
    }
    foreach ($dataSet['earliest_donor'] as $address) {
      $this->callAPISuccess($dataSet['entity'], 'create', array_merge(['contact_id' => $this->contactID2], $address));
    }
    foreach ($dataSet['most_recent_donor'] as $address) {
      $this->callAPISuccess($dataSet['entity'], 'create', array_merge(['contact_id' => $this->contactID], $address));
    }

    $result = $this->callAPISuccess('Job', 'process_batch_merge', ['mode' => 'safe']);

    $this->assertCount($dataSet['skipped'], $result['values']['skipped']);
    $this->assertCount($dataSet['merged'], $result['values']['merged']);
    if ($dataSet['merged']) {
      // higher contact merged into this so we are interested in this contact.
      $keptContact = $this->contactID;
    }
    else {
      // ie. no merge has taken place, so we just going to check our contact2 is unchanged.
      $keptContact = $this->contactID2;
    }
    $addresses = $this->callAPISuccess($dataSet['entity'], 'get', [
      'contact_id' => $keptContact,
      'sequential' => 1,
    ])['values'];

    if (!empty($dataSet['fix_required_for_reverse'])) {
      return;
    }
    $this->assertCount(count($dataSet['expected_hook']), $addresses);
    $locationTypes = $this->callAPISuccess($dataSet['entity'], 'getoptions', ['field' => 'location_type_id']);
    foreach ($dataSet['expected_hook'] as $expectedAddress) {
      foreach ($addresses as $index => $address) {
        // compared to the previous test the addresses are in a different order (for some datasets.
        // so, first find the matching address and then check it fully matches.
        // by unsetting afterwards we should find them all gone by the end.
        if (
          (!empty($address['street_address']) && $address['street_address'] === $expectedAddress['street_address'])
          || (!empty($address['phone']) && $address['phone'] === $expectedAddress['phone'])
          || (!empty($address['email']) && $address['email'] === $expectedAddress['email'])
        ) {
          foreach ($expectedAddress as $key => $value) {
            if ($key === 'location_type_id') {
              $this->assertEquals($locationTypes['values'][$addresses[$index][$key]], $value);
            }
            else {
              $this->assertEquals($value, $addresses[$index][$key], $dataSet['entity'] . ': Unexpected value for ' . $key . (!empty($dataSet['description']) ? " on dataset {$dataSet['description']}" : ''));
            }
          }
          unset($addresses[$index]);
          // break to find a match for the next $expected address.
          continue 2;
        }
      }
    }
    $this->assertEmpty($addresses);
  }

  /**
   * Test that a conflict on 'on_hold' is handled.
   *
   * This is now handled in the dedupetools extension with resolution being
   * 'keep the YES'. We continue to test here for good measure.
   *
   * @throws \CRM_Core_Exception
   */
  public function testBatchMergeConflictOnHold() {
    $emailDuck1 = $this->callAPISuccess('Email', 'get', ['contact_id' => $this->contactID, 'return' => 'id']);
    $this->giveADuckADonation(FALSE);
    $this->callAPISuccess('Email', 'create', ['id' => $emailDuck1['id'], 'on_hold' => 1]);
    $result = $this->callAPISuccess('Job', 'process_batch_merge', ['mode' => 'safe']);
    $this->assertCount(0, $result['values']['skipped']);
    $this->assertCount(1, $result['values']['merged']);
    $email = $this->callAPISuccessGetSingle('Email', ['contact_id' => $this->contactID]);
    $this->assertEquals(1, $email['on_hold']);

    $this->callAPISuccess('Email', 'create', ['id' => $email['id'], 'on_hold' => 0]);
    $duck2 = $this->breedDuck([wmf_civicrm_get_custom_field_name('do_not_solicit') => 1]);
    $emailDuck2 = $this->callAPISuccess('Email', 'get', ['contact_id' => $duck2, 'return' => 'id']);
    $this->callAPISuccess('Email', 'create', ['id' => $emailDuck2['id'], 'on_hold' => 1]);

    $result = $this->callAPISuccess('Job', 'process_batch_merge', ['mode' => 'safe']);
    $this->assertCount(0, $result['values']['skipped']);
    $this->assertCount(1, $result['values']['merged']);
    $email = $this->callAPISuccessGetSingle('Email', ['contact_id' => $this->contactID]);
    $this->assertEquals(1, $email['on_hold']);
  }

  /**
   * Test that a conflict on communication preferences is handled.
   *
   * @throws \CRM_Core_Exception
   */
  public function testBatchMergeConflictCommunicationPreferences() {
    $this->callAPISuccess('Contact', 'create', ['id' => $this->contactID, 'do_not_email' => FALSE, 'is_opt_out' => TRUE]);
    $this->callAPISuccess('Contact', 'create', ['id' => $this->contactID2, 'do_not_email' => TRUE, 'is_opt_out' => FALSE]);

    $result = $this->callAPISuccess('Job', 'process_batch_merge', ['mode' => 'safe']);
    $this->assertCount(0, $result['values']['skipped']);
    $this->assertCount(1, $result['values']['merged']);

    $contact = $this->callAPISuccess('Contact', 'get', ['id' => $this->contactID, 'sequential' => 1]);
    $this->assertEquals(1, $contact['values'][0]['is_opt_out']);
    $this->assertEquals(1, $contact['values'][0]['do_not_email']);
  }


  /**
   * Test that a conflict on communication preferences is handled.
   *
   * @dataProvider getLanguageCombos
   *
   * @param array $dataSet
   *
   * @throws \CRM_Core_Exception
   * @throws \CiviCRM_API3_Exception
   */
  public function testBatchMergeConflictPreferredLanguage($dataSet) {
    // Can't use api if we are trying to use invalid data.
    wmf_civicrm_ensure_language_exists('en');
    wmf_civicrm_ensure_language_exists('en_NZ');
    \CRM_Core_DAO::executeQuery("UPDATE civicrm_contact SET preferred_language = '{$dataSet['languages'][0]}' WHERE id = $this->contactID");
    \CRM_Core_DAO::executeQuery("UPDATE civicrm_contact SET preferred_language = '{$dataSet['languages'][1]}' WHERE id = $this->contactID2");

    $result = $this->callAPISuccess('Job', 'process_batch_merge', ['mode' => 'safe']);
    if ($dataSet['is_conflict']) {
      $this->assertCount(1, $result['values']['skipped']);
    }
    else {
      $this->assertCount(1, $result['values']['merged']);
      $contact = $this->callAPISuccess('Contact', 'get', [
        'id' => $this->contactID,
        'sequential' => 1,
      ]);
      $this->assertEquals($dataSet['selected'], $contact['values'][0]['preferred_language']);
    }
  }

  /**
   * Test that a conflict on communication preferences is handled.
   *
   * @dataProvider getDifferentLanguageCombos
   *
   * @param string $language1
   * @param string $language2
   *
   * @throws \CRM_Core_Exception
   * @throws \CiviCRM_API3_Exception
   */
  public function testBatchMergeConflictDifferentPreferredLanguage($language1, $language2) {
    // Can't use api if we are trying to use invalid data.
    $this->contributionCreate(['contact_id' => $this->contactID, 'receive_date' => '2010-01-01', 'invoice_id' => 1, 'trxn_id' => 1]);
    $this->contributionCreate(['contact_id' => $this->contactID2, 'receive_date' => '2012-01-01', 'invoice_id' => 2, 'trxn_id' => 2]);

    wmf_civicrm_ensure_language_exists('en_US');
    wmf_civicrm_ensure_language_exists('fr_FR');
    \CRM_Core_DAO::executeQuery("UPDATE civicrm_contact SET preferred_language = '$language1' WHERE id = $this->contactID");
    \CRM_Core_DAO::executeQuery("UPDATE civicrm_contact SET preferred_language = '$language2' WHERE id = $this->contactID2");

    $result = $this->callAPISuccess('Job', 'process_batch_merge', ['mode' => 'safe']);
    $this->assertCount(1, $result['values']['merged']);
    $contact = $this->callAPISuccess('Contact', 'get', [
      'id' => $this->contactID,
      'sequential' => 1,
    ]);
    $this->assertEquals($language2, $contact['values'][0]['preferred_language']);
  }

  /**
   * Test that a conflict on communication preferences is handled.
   *
   * This is the same as the other test except the contact with the lower id is
   * the later donor.
   *
   * @dataProvider getDifferentLanguageCombos
   *
   * @param string $language1
   * @param string $language2
   *
   * @throws \CRM_Core_Exception
   * @throws \CiviCRM_API3_Exception
   */
  public function testBatchMergeConflictDifferentPreferredLanguageReverse($language1, $language2) {
    // Can't use api if we are trying to use invalid data.
    $this->contributionCreate(['contact_id' => $this->contactID, 'receive_date' => '2012-01-01', 'invoice_id' => 1, 'trxn_id' => 1]);
    $this->contributionCreate(['contact_id' => $this->contactID2, 'receive_date' => '2010-01-01', 'invoice_id' => 2, 'trxn_id' => 2]);

    wmf_civicrm_ensure_language_exists('en_US');
    wmf_civicrm_ensure_language_exists('fr_FR');
    \CRM_Core_DAO::executeQuery("UPDATE civicrm_contact SET preferred_language = '$language1' WHERE id = $this->contactID");
    \CRM_Core_DAO::executeQuery("UPDATE civicrm_contact SET preferred_language = '$language2' WHERE id = $this->contactID2");

    $result = $this->callAPISuccess('Job', 'process_batch_merge', ['mode' => 'safe']);
    $this->assertCount(1, $result['values']['merged']);
    $contact = $this->callAPISuccess('Contact', 'get', [
      'id' => $this->contactID,
      'sequential' => 1,
    ]);
    $this->assertEquals($language1, $contact['values'][0]['preferred_language']);
  }

  /**
   * Get combinations of languages for comparison.
   *
   * @return array
   */
  public function getLanguageCombos(): array {
    return [
      // Choose longer.
      [['languages' => ['en', 'en_US'], 'is_conflict' => FALSE, 'selected' => 'en_US']],
      [['languages' => ['en_US', 'en'], 'is_conflict' => FALSE, 'selected' => 'en_US']],
      // Choose valid.
      [['languages' => ['en_XX', 'en_US'], 'is_conflict' => FALSE, 'selected' => 'en_US']],
      [['languages' => ['en_US', 'en_XX'], 'is_conflict' => FALSE, 'selected' => 'en_US']],
      // Chose one with a 'real' label  (more valid).
      [['languages' => ['en_US', 'en_NZ'], 'is_conflict' => FALSE, 'selected' => 'en_US']],
      [['languages' => ['en_NZ', 'en_US'], 'is_conflict' => FALSE, 'selected' => 'en_US']],
      // Chose either - feels like the return on coding any decision making now is negligible.
      // Could go for most recent donor but feels like no return on effort.
      // we will usually get the most recent donor anyway by default - as it merges higher number to smaller.
      [['languages' => ['en_GB', 'en_US'], 'is_conflict' => FALSE, 'selected' => 'en_US']],
      [['languages' => ['en_US', 'en_GB'], 'is_conflict' => FALSE, 'selected' => 'en_GB']],
    ];
  }

  /**
   * Get combinations of languages for comparison.
   *
   * @return array
   */
  public function getDifferentLanguageCombos(): array {
    return [
      // Choose longer.
      ['fr_FR', 'en_US'],
      ['en_US', 'fr_FR'],
    ];
  }

  /**
   * Test that source conflicts are ignored.
   *
   * We don't care enough about source it seems to do much with it.
   *
   * Update - source is now handled by the deduper - which prioritises
   * our preferred contact.
   *
   * Bug T146946
   *
   * @throws \CRM_Core_Exception
   */
  public function testBatchMergeConflictSource() {
    $this->breedDuck(['id' => $this->contactID, 'source' => 'egg']);
    $this->breedDuck(['id' => $this->contactID2, 'source' => 'chicken']);
    $result = $this->callAPISuccess('Job', 'process_batch_merge', ['mode' => 'safe']);
    $this->assertCount(0, $result['values']['skipped']);
    $this->assertCount(1, $result['values']['merged']);
  }

  /**
   * Test that we keep the opt in from the most recent donor.
   *
   * The handling for this is in the dedupe tools. Testing in our code checks
   * our settings have been added.
   *
   * @param bool $isReverse
   *
   * @dataProvider isReverse
   * @throws \CRM_Core_Exception
   */
  public function testBatchMergeConflictOptIn($isReverse) {
    $this->breedGenerousDuck($this->contactID, [wmf_civicrm_get_custom_field_name('opt_in') => 1], !$isReverse);
    $this->breedGenerousDuck($this->contactID2, [wmf_civicrm_get_custom_field_name('opt_in') => 0], $isReverse);
    $result = $this->callAPISuccess('Job', 'process_batch_merge', ['mode' => 'safe']);
    $this->assertCount(0, $result['values']['skipped'], 'skipped count is wrong');
    $this->assertCount(1, $result['values']['merged'], 'merged count is wrong');
    $contact = $this->callAPISuccessGetSingle('Contact', ['id' => $this->contactID, 'return' => wmf_civicrm_get_custom_field_name('opt_in')]);
    $this->assertEquals($isReverse ? 0 : 1, $contact[wmf_civicrm_get_custom_field_name('opt_in')]);
  }

  /**
   * Test that whitespace conflicts are resolved.
   *
   * Bug T146946
   *
   * @throws \CRM_Core_Exception
   */
  public function testBatchMergeResolvableConflictWhiteSpace() {
    $this->breedDuck(['id' => $this->contactID, 'first_name' => 'alter ego']);
    $this->breedDuck(['id' => $this->contactID2, 'first_name' => 'alterego']);
    $result = $this->callAPISuccess('Job', 'process_batch_merge', ['mode' => 'safe']);
    $this->assertCount(1, $result['values']['merged']);
    $contact = $this->callAPISuccessGetSingle('Contact', ['email' => 'the_don@duckland.com']);
    $this->assertEquals('alter ego', $contact['first_name']);
  }

  /**
   * Test that punctuation conflicts are ignored.
   *
   * Bug T175748
   *
   * @throws \CRM_Core_Exception
   */
  public function testBatchMergeResolvableConflictPunctuation() {
    $this->breedDuck(['id' => $this->contactID, 'first_name' => 'alter. ego']);
    $this->breedDuck(['id' => $this->contactID2, 'first_name' => 'alterego']);
    $result = $this->callAPISuccess('Job', 'process_batch_merge', ['mode' => 'safe']);
    $this->assertCount(1, $result['values']['merged']);
    $contact = $this->callAPISuccessGetSingle('Contact', ['email' => 'the_don@duckland.com']);
    $this->assertEquals('alter ego', $contact['first_name']);
  }

  /**
   * Test that we ignore numbers as names.
   *
   * Bug T175747
   *
   * @throws \CRM_Core_Exception
   */
  public function testBatchMergeResolvableConflictNumbersAreNotPeople() {
    $this->breedDuck(['id' => $this->contactID, 'first_name' => 'alter. ego']);
    $this->breedDuck(['id' => $this->contactID2, 'first_name' => '1']);
    $result = $this->callAPISuccess('Job', 'process_batch_merge', ['mode' => 'safe']);
    $this->assertCount(1, $result['values']['merged']);
    $contact = $this->callAPISuccessGetSingle('Contact', ['email' => 'the_don@duckland.com']);
    $this->assertEquals('alter ego', $contact['first_name']);
  }

  /**
   * Test that we don't see country only as conflicting with country plus.
   *
   * In this variant the most recent donor is the one with the lower contact
   * ID (the one we are going to keep). Real world this is pretty rare but
   * perhaps after some merging in strange orders it could happen.
   *
   * Bug T176699
   *
   * @throws \CRM_Core_Exception
   * @throws \CiviCRM_API3_Exception
   */
  public function testBatchMergeResolvableConflictCountryVsFullAddressOutOfOrder() {
    $this->callAPISuccess('Address', 'create', [
      'country_id' => 'MX',
      'contact_id' => $this->contactID,
      'location_type_id' => 1,
      'is_primary' => 1,
    ]);
    $this->callAPISuccess('Address', 'create', [
      'country_id' => 'MX',
      'contact_id' => $this->contactID2,
      'street_address' => 'First on the left after you cross the border',
      'location_type_id' => 1,
      'is_primary' => 1,
    ]);
    $this->callAPISuccess('Address', 'create', [
      'country_id' => 'MX',
      'contact_id' => $this->contactID2,
      'street_address' => 'A different address',
      'location_type_id' => 2,
    ]);
    // this is the change.
    $this->contributionCreate(['contact_id' => $this->contactID, 'receive_date' => '2010-01-01', 'total_amount' => 500]);

    $result = $this->callAPISuccess('Job', 'process_batch_merge', ['mode' => 'safe']);
    $this->assertCount(1, $result['values']['merged']);
    $contact = $this->callAPISuccessGetSingle('Contact', ['email' => 'the_don@duckland.com']);
    $this->assertEquals('Mexico', $contact['country']);
    $this->assertEquals('First on the left after you cross the border', $contact['street_address']);
    $address = $this->callAPISuccessGetSingle('Address', ['street_address' => 'A different address']);
    $this->assertEquals($contact['id'], $address['contact_id']);
    $numPrimaries = civicrm_api3('Address', 'getcount', ['contact_id' => $contact['id'], 'is_primary' => 1]);
    $this->assertEquals(1, $numPrimaries);
  }

  /**
   * Test that we don't see country only as conflicting with country plus.
   *
   * In this case the 'keeper' is against the second contact.
   *
   * Bug T176699
   *
   * @throws \CRM_Core_Exception
   */
  public function testBatchMergeResolvableConflictCountryVsFullAddressReverseOutOfOrder() {
    $this->callAPISuccess('Address', 'create', [
      'country_id' => 'MX',
      'contact_id' => $this->contactID2,
      'location_type_id' => 1,
      'is_primary' => 1,
    ]);
    $this->callAPISuccess('Address', 'create', [
      'country_id' => 'MX',
      'contact_id' => $this->contactID,
      'street_address' => 'First on the left after you cross the border',
      'location_type_id' => 1,
      'is_primary' => 1,
    ]);
    $this->callAPISuccess('Address', 'create', [
      'country_id' => 'MX',
      'contact_id' => $this->contactID,
      'street_address' => 'A different address',
      'location_type_id' => 2,
    ]);
    $this->contributionCreate(['contact_id' => $this->contactID, 'receive_date' => '2010-01-01', 'total_amount' => 500]);

    $result = $this->callAPISuccess('Job', 'process_batch_merge', ['mode' => 'safe']);
    $this->assertCount(1, $result['values']['merged']);
    $contact = $this->callAPISuccessGetSingle('Contact', ['email' => 'the_don@duckland.com']);
    $this->assertEquals('Mexico', $contact['country']);
    $this->assertEquals('First on the left after you cross the border', $contact['street_address']);
    $address = $this->callAPISuccessGetSingle('Address', ['street_address' => 'A different address']);
    $this->assertEquals($contact['id'], $address['contact_id']);
    $numPrimaries = $this->callAPISuccessGetCount('Address', ['contact_id' => $contact['id'], 'is_primary' => 1]);
    $this->assertEquals(1, $numPrimaries);
  }

  /**
   * Test that we don't see a city named after a country as the same as a country.
   *
   * UPDATE - this is now merged, keeping most recent donor - ie. 1 since
   * that is the only one with a donation.
   *
   * Bug T176699
   *
   * @throws \CRM_Core_Exception
   */
  public function testBatchMergeUnResolvableConflictCityLooksCountryishWithCounty() {
    $this->callAPISuccess('Address', 'create', [
      'country_id' => 'US',
      'contact_id' => $this->contactID2,
      'city' => 'Mexico',
      'location_type_id' => 1,
    ]);
    $this->callAPISuccess('Address', 'create', [
      'country_id' => 'MX',
      'contact_id' => $this->contactID,
      'street_address' => 'First on the left after you cross the border',
      'location_type_id' => 1,
    ]);
    $this->contributionCreate(['contact_id' => $this->contactID, 'receive_date' => '2010-01-01', 'total_amount' => 500]);

    $result = $this->callAPISuccess('Job', 'process_batch_merge', ['mode' => 'safe']);
    $this->assertCount(0, $result['values']['skipped']);
    $this->assertCount(1, $result['values']['merged']);

    $address = $this->callAPISuccessGetSingle('Address', ['contact_id' => $this->contactID]);
    $this->assertEquals('First on the left after you cross the border', $address['street_address']);
    $this->assertEquals('MX', \CRM_Core_PseudoConstant::countryIsoCode($address['country_id']));
    $this->assertNotTrue(isset($address['city']));

  }

  /**
   * Test that we don't see a city named after a country as the same as a country
   * when it has no country.
   *
   * UPDATE - this is now merged, keeping most recent donor - ie. 1 since
   * that is the only one with a donation.
   *
   * Bug T176699
   *
   * @throws \CRM_Core_Exception
   */
  public function testBatchMergeUnResolvableConflictCityLooksCountryishNoCountry() {
    $this->callAPISuccess('Address', 'create', [
      'contact_id' => $this->contactID2,
      'city' => 'Mexico',
      'location_type_id' => 1,
    ]);
    $this->callAPISuccess('Address', 'create', [
      'country_id' => 'MX',
      'contact_id' => $this->contactID,
      'street_address' => 'First on the left after you cross the border',
      'location_type_id' => 1,
    ]);
    $this->contributionCreate(['contact_id' => $this->contactID, 'receive_date' => '2010-01-01', 'total_amount' => 500]);

    $result = $this->callAPISuccess('Job', 'process_batch_merge', ['mode' => 'safe']);
    $this->assertCount(0, $result['values']['skipped']);
    $this->assertCount(1, $result['values']['merged']);

    $address = $this->callAPISuccessGetSingle('Address', ['contact_id' => $this->contactID]);
    $this->assertEquals('First on the left after you cross the border', $address['street_address']);
    $this->assertEquals('MX', \CRM_Core_PseudoConstant::countryIsoCode($address['country_id']));
    $this->assertNotTrue(isset($address['city']));
  }

  /**
   * Test that we still cope when there is no address conflict....
   *
   * Bug T176699
   *
   * @throws \CRM_Core_Exception
   */
  public function testBatchMergeNoRealConflictOnAddressButAnotherConflictResolved() {
    $this->callAPISuccess('Address', 'create', [
      'contact_id' => $this->contactID2,
      'country' => 'Korea, Republic of',
      'location_type_id' => 1,
    ]);
    $this->callAPISuccess('Address', 'create', [
      'contact_id' => $this->contactID,
      'country' => 'Korea, Republic of',
      'location_type_id' => 1,
    ]);
    $this->contributionCreate(['contact_id' => $this->contactID, 'receive_date' => '2010-01-01', 'total_amount' => 500]);

    $result = $this->callAPISuccess('Job', 'process_batch_merge', ['mode' => 'safe']);
    $this->assertCount(0, $result['values']['skipped']);
    $this->assertCount(1, $result['values']['merged']);
  }

  /**
   * Test that we don't see a city named after a country as the same as a country
   * when it has no country.
   *
   * UPDATE - this is now merged, keeping most recent donor - ie. 1 since
   * that is the only one with a donation.
   *
   * Bug T176699
   *
   * @throws \CRM_Core_Exception
   */
  public function testBatchMergeUnResolvableConflictRealConflict() {
    $this->callAPISuccess('Address', 'create', [
      'contact_id' => $this->contactID2,
      'city' => 'Poland',
      'country_id' => 'US',
      'state_province' => 'ME',
      'location_type_id' => 1,
    ]);
    $this->callAPISuccess('Address', 'create', [
      'country' => 'Poland',
      'contact_id' => $this->contactID,
      'location_type_id' => 1,
    ]);
    $this->contributionCreate(['contact_id' => $this->contactID, 'receive_date' => '2010-01-01', 'total_amount' => 500]);

    $result = $this->callAPISuccess('Job', 'process_batch_merge', ['mode' => 'safe']);
    $this->assertCount(0, $result['values']['skipped']);
    $this->assertCount(1, $result['values']['merged']);

    $address = $this->callAPISuccessGetSingle('Address', ['contact_id' => $this->contactID]);
    $this->assertEquals('PL', \CRM_Core_PseudoConstant::countryIsoCode($address['country_id']));
    $this->assertNotTrue(isset($address['city']));
  }

  /**
   * Test that a conflict on casing in first names is handled for organization_name.
   *
   * @throws \CRM_Core_Exception
   */
  public function testBatchMergeConflictNameCasingOrgs() {
    $rule_group_id = (int) $this->callAPISuccessGetValue('RuleGroup', [
      'contact_type' => 'Organization',
      'used' => 'Unsupervised',
      'return' => 'id',
      'options' => ['limit' => 1],
    ]);

    // Do a pre-merge to get us to a known 'no mergeable contacts' state.
    $this->callAPISuccess('Job', 'process_batch_merge', ['mode' => 'safe', 'rule_group_id' => $rule_group_id]);

    $org1 = $this->callAPISuccess('Contact', 'create', ['organization_name' => 'donald duck', 'contact_type' => 'Organization']);
    $this->callAPISuccess('Contact', 'create', ['organization_name' => 'Donald Duck', 'contact_type' => 'Organization']);

    $result = $this->callAPISuccess('Job', 'process_batch_merge', ['mode' => 'safe', 'rule_group_id' => $rule_group_id]);
    $this->assertCount(0, $result['values']['skipped']);
    $this->assertCount(1, $result['values']['merged']);

    $contact = $this->callAPISuccess('Contact', 'get', ['id' => $org1['id'], 'sequential' => 1]);
    $this->assertEquals('Donald Duck', $contact['values'][0]['organization_name']);
  }

  /**
   * Get address combinations for the merge test.
   *
   * @return array
   */
  public function getMergeLocationData(): array {
    $address1 = ['street_address' => 'Buckingham Palace', 'city' => 'London'];
    $address2 = ['street_address' => 'The Doghouse', 'supplemental_address_1' => 'under the blanket'];
    $address3 = ['street_address' => 'Downton Abbey'];
    $data = $this->getMergeLocations($address1, $address2, $address3, 'Address');
    $data = array_merge($data, $this->getMergeLocations(
      ['phone' => '12345', 'phone_type_id' => 1],
      ['phone' => '678910', 'phone_type_id' => 1],
      ['phone' => '999888', 'phone_type_id' => 1],
      'Phone')
    );
    $data = array_merge($data, $this->getMergeLocations(['phone' => '12345'], ['phone' => '678910'], ['phone' => '678999'], 'Phone'));
    $data = array_merge($data, $this->getMergeLocations(
      ['email' => 'mini@me.com'],
      ['email' => 'mini@me.org'],
      ['email' => 'mini@me.co.nz'],
      'Email',
      [
        [
          'email' => 'the_don@duckland.com',
          'location_type_id' => 'Work',
        ],
      ]));
    return $data;

  }

  /**
   * Get the location data set.
   *
   * @param array $locationParams1
   * @param array $locationParams2
   * @param array $locationParams3
   * @param string $entity
   * @param array $additionalExpected
   *
   * @return array
   */
  public function getMergeLocations($locationParams1, $locationParams2, $locationParams3, $entity, $additionalExpected = []): array {
    return [
      'matching_primary' => [
        'matching_primary' => [
          'merged' => 1,
          'skipped' => 0,
          'is_major_gifts' => 1,
          'description' => 'Same behaviour with & without the hook, matching primary AND other address maintained',
          'entity' => $entity,
          'earliest_donor' => [
            array_merge([
              'location_type_id' => 'Home',
              'is_primary' => 1,
            ], $locationParams1),
            array_merge([
              'location_type_id' => 'Mailing',
              'is_primary' => 0,
            ], $locationParams2),
          ],
          'most_recent_donor' => [
            array_merge([
              'location_type_id' => 'Home',
              'is_primary' => 1,
            ], $locationParams1),
          ],
          'expected_hook' => array_merge($additionalExpected, [
            array_merge([
              'location_type_id' => 'Home',
              'is_primary' => 1,
            ], $locationParams1),
            array_merge([
              'location_type_id' => 'Mailing',
              'is_primary' => 0,
            ], $locationParams2),
          ]),
        ],
      ],
      'matching_primary_reverse' => [
        'matching_primary_reverse' => [
          'merged' => 1,
          'skipped' => 0,
          'is_major_gifts' => 1,
          'description' => 'Same behaviour with & without the hook, matching primary AND other address maintained',
          'entity' => $entity,
          'earliest_donor' => [
            array_merge([
              'location_type_id' => 'Home',
              'is_primary' => 1,
            ], $locationParams1),
          ],
          'most_recent_donor' => [
            array_merge([
              'location_type_id' => 'Home',
              'is_primary' => 1,
            ], $locationParams1),
            array_merge([
              'location_type_id' => 'Mailing',
              'is_primary' => 0,
            ], $locationParams2),
          ],
          'expected_hook' => array_merge($additionalExpected, [
            array_merge([
              'location_type_id' => 'Home',
              'is_primary' => 1,
            ], $locationParams1),
            array_merge([
              'location_type_id' => 'Mailing',
              'is_primary' => 0,
            ], $locationParams2),
          ]),
        ],
      ],
      'only_one_has_address' => [
        'only_one_has_address' => [
          'merged' => 1,
          'skipped' => 0,
          'is_major_gifts' => 1,
          'description' => 'Same behaviour with & without the hook, address is maintained',
          'entity' => $entity,
          'earliest_donor' => [
            array_merge([
              'location_type_id' => 'Home',
              'is_primary' => 1,
            ], $locationParams1),
            array_merge([
              'location_type_id' => 'Mailing',
              'is_primary' => 0,
            ], $locationParams2),
          ],
          'most_recent_donor' => [],
          'expected_hook' => array_merge($additionalExpected, [
            array_merge([
              'location_type_id' => 'Home',
              // When dealing with email we don't have a clean slate - the existing
              // primary will be primary.
              'is_primary' => ($entity === 'Email' ? 0 : 1),
            ], $locationParams1),
            array_merge([
              'location_type_id' => 'Mailing',
              'is_primary' => 0,
            ], $locationParams2),
          ]),
        ],
      ],
      'only_one_has_address_reverse' => [
        'only_one_has_address_reverse' => [
          'merged' => 1,
          'skipped' => 0,
          'is_major_gifts' => 1,
          'description' => 'Same behaviour with & without the hook, address is maintained',
          'entity' => $entity,
          'earliest_donor' => [],
          'most_recent_donor' => [
            array_merge([
              'location_type_id' => 'Home',
              'is_primary' => 1,
            ], $locationParams1),
            array_merge([
              'location_type_id' => 'Mailing',
              'is_primary' => 0,
            ], $locationParams2),
          ],
          'expected_hook' => array_merge($additionalExpected, [
            array_merge([
              'location_type_id' => 'Home',
              'is_primary' => 1,
            ], $locationParams1),
            array_merge([
              'location_type_id' => 'Mailing',
              'is_primary' => 0,
            ], $locationParams2),
          ]),
        ],
      ],
      'different_primaries_with_different_location_type' => [
        'different_primaries_with_different_location_type' => [
          'merged' => 1,
          'skipped' => 0,
          'is_major_gifts' => 1,
          'description' => 'Primaries are different with different location. Keep both addresses. Set primary to be that of more recent donor',
          'entity' => $entity,
          'earliest_donor' => [
            array_merge([
              'location_type_id' => 'Home',
              'is_primary' => 1,
            ], $locationParams1),
          ],
          'most_recent_donor' => [
            array_merge([
              'location_type_id' => 'Mailing',
              'is_primary' => 1,
            ], $locationParams2),
          ],
          'expected_hook' => array_merge($additionalExpected, [
            array_merge([
              'location_type_id' => 'Home',
              'is_primary' => 0,
            ], $locationParams1),
            array_merge([
              'location_type_id' => 'Mailing',
              'is_primary' => 1,
            ], $locationParams2),
          ]),
        ],
      ],
      'different_primaries_with_different_location_type_reverse' => [
        'different_primaries_with_different_location_type_reverse' => [
          'merged' => 1,
          'skipped' => 0,
          'is_major_gifts' => 1,
          'entity' => $entity,
          'earliest_donor' => [
            array_merge([
              'location_type_id' => 'Mailing',
              'is_primary' => 1,
            ], $locationParams2),
          ],
          'most_recent_donor' => [
            array_merge([
              'location_type_id' => 'Home',
              'is_primary' => 1,
            ], $locationParams1),
          ],
          'expected_hook' => array_merge($additionalExpected, [
            array_merge([
              'location_type_id' => 'Mailing',
              'is_primary' => 0,
            ], $locationParams2),
            array_merge([
              'location_type_id' => 'Home',
              'is_primary' => 1,
            ], $locationParams1),
          ]),
        ],
      ],
      'different_primaries_location_match_only_one_address' => [
        'different_primaries_location_match_only_one_address' => [
          'merged' => 1,
          'skipped' => 0,
          'is_major_gifts' => 1,
          'entity' => $entity,
          'earliest_donor' => [
            array_merge([
              'location_type_id' => 'Home',
              'is_primary' => 1,
            ], $locationParams1),
            array_merge([
              'location_type_id' => 'Mailing',
              'is_primary' => 0,
            ], $locationParams2),
          ],
          'most_recent_donor' => [
            array_merge([
              'location_type_id' => 'Mailing',
              'is_primary' => 1,
            ], $locationParams2),

          ],
          'expected_hook' => array_merge($additionalExpected, [
            array_merge([
              'location_type_id' => 'Home',
              'is_primary' => 0,
            ], $locationParams1),
            array_merge([
              'location_type_id' => 'Mailing',
              'is_primary' => 1,
            ], $locationParams2),
          ]),
        ],
      ],
      'different_primaries_location_match_only_one_address_reverse' => [
        'different_primaries_location_match_only_one_address_reverse' => [
          'merged' => 1,
          'skipped' => 0,
          'is_major_gifts' => 1,
          'entity' => $entity,
          'earliest_donor' => [
            array_merge([
              'location_type_id' => 'Mailing',
              'is_primary' => 1,
            ], $locationParams2),
          ],
          'most_recent_donor' => [
            array_merge([
              'location_type_id' => 'Home',
              'is_primary' => 1,
            ], $locationParams1),
            array_merge([
              'location_type_id' => 'Mailing',
              'is_primary' => 0,
            ], $locationParams2),
          ],
          'expected_hook' => array_merge($additionalExpected, [
            array_merge([
              'location_type_id' => 'Mailing',
              'is_primary' => 0,
            ], $locationParams2),
            array_merge([
              'location_type_id' => 'Home',
              'is_primary' => 1,
            ], $locationParams1),
          ]),
        ],
      ],
      'same_primaries_different_location' => [
        'same_primaries_different_location' => [
          'fix_required_for_reverse' => 1,
          'comment' => 'core is not identifying this as an address conflict in reverse order'
            . ' this is not an issue at the moment as it only happens in reverse from the'
            . 'form merge - where we do not intervene',
          'merged' => 1,
          'skipped' => 0,
          'is_major_gifts' => 1,
          'entity' => $entity,
          'earliest_donor' => [
            array_merge([
              'location_type_id' => 'Home',
              'is_primary' => 1,
            ], $locationParams1),
          ],
          'most_recent_donor' => [
            array_merge([
              'location_type_id' => 'Mailing',
              'is_primary' => 1,
            ], $locationParams1),

          ],
          'expected_hook' => array_merge($additionalExpected, [
            array_merge([
              'location_type_id' => 'Mailing',
              'is_primary' => 1,
            ], $locationParams1),
          ]),
        ],
      ],
      'same_primaries_different_location_reverse' => [
        'same_primaries_different_location_reverse' => [
          'fix_required_for_reverse' => 1,
          'comment' => 'core is not identifying this as an address conflict in reverse order'
            . ' this is not an issue at the moment as it only happens in reverse from the'
            . 'form merge - where we do not intervene',
          'merged' => 1,
          'skipped' => 0,
          'is_major_gifts' => 1,
          'entity' => $entity,
          'earliest_donor' => [
            array_merge([
              'location_type_id' => 'Mailing',
              'is_primary' => 1,
            ], $locationParams1),
          ],
          'most_recent_donor' => [
            array_merge([
              'location_type_id' => 'Home',
              'is_primary' => 1,
            ], $locationParams1),
          ],
          'expected_hook' => array_merge($additionalExpected, [
            array_merge([
              'location_type_id' => 'Home',
              'is_primary' => 1,
            ], $locationParams1),
          ]),
        ],
      ],
      'conflicting_home_address_major_gifts' => [
        'conflicting_home_address_major_gifts' => [
          'merged' => 1,
          'skipped' => 0,
          'is_major_gifts' => 1,
          'entity' => $entity,
          'earliest_donor' => [
            array_merge([
              'location_type_id' => 'Home',
              'is_primary' => 1,
            ], $locationParams1),
          ],
          'most_recent_donor' => [
            array_merge([
              'location_type_id' => 'Home',
              'is_primary' => 1,
            ], $locationParams2),
          ],
          'expected_hook' => array_merge($additionalExpected, [
            array_merge([
              'location_type_id' => 'Home',
              'is_primary' => 1,
            ], $locationParams2),
          ]),
        ],
      ],
      'conflicting_home_address_not_major_gifts' => [
        'conflicting_home_address_not_major_gifts' => [
          'fix_required_for_reverse' => 1,
          'comment' => 'our code needs an update as both are being kept'
            . ' this is not an issue at the moment as it only happens in reverse from the'
            . 'form merge - where we do not intervene',
          'merged' => 1,
          'skipped' => 0,
          'is_major_gifts' => 0,
          'entity' => $entity,
          'earliest_donor' => [
            array_merge([
              'location_type_id' => 'Home',
              'is_primary' => 1,
            ], $locationParams1),
          ],
          'most_recent_donor' => [
            array_merge([
              'location_type_id' => 'Home',
              'is_primary' => 1,
            ], $locationParams2),
          ],
          'expected_hook' => array_merge($additionalExpected, [
            array_merge([
              'location_type_id' => 'Home',
              'is_primary' => 1,
            ], $locationParams2),
          ]),
        ],
      ],
      'conflicting_home_address_one_more_major_gifts' => [
        'conflicting_home_address_one_more_major_gifts' => [
          'merged' => 1,
          'skipped' => 0,
          'is_major_gifts' => 1,
          'entity' => $entity,
          'earliest_donor' => [
            array_merge([
              'location_type_id' => 'Home',
              'is_primary' => 0,
            ], $locationParams1),
            array_merge([
              'location_type_id' => 'Mailing',
              'is_primary' => 1,
            ], $locationParams2),
          ],
          'most_recent_donor' => [
            array_merge([
              'location_type_id' => 'Home',
              'is_primary' => 0,
            ], $locationParams3),
            array_merge([
              'location_type_id' => 'Mailing',
              'is_primary' => 1,
            ], $locationParams2),
          ],
          'expected_hook' => array_merge($additionalExpected, [
            array_merge([
              'location_type_id' => 'Mailing',
              'is_primary' => 1,
            ], $locationParams2),
            array_merge([
              'location_type_id' => 'Home',
              'is_primary' => 0,
            ], $locationParams3),
          ]),
        ],
      ],
      'conflicting_home_address__one_more_not_major_gifts' => [
        'conflicting_home_address__one_more_not_major_gifts' => [
          'fix_required_for_reverse' => 1,
          'comment' => 'our code needs an update as an extra 1 is being kept'
            . ' this is not an issue at the moment as it only happens in reverse from the'
            . 'form merge - where we do not intervene',
          'merged' => 1,
          'skipped' => 0,
          'is_major_gifts' => 0,
          'entity' => $entity,
          'earliest_donor' => [
            array_merge([
              'location_type_id' => 'Home',
              'is_primary' => 0,
            ], $locationParams1),
            array_merge([
              'location_type_id' => 'Mailing',
              'is_primary' => 1,
            ], $locationParams2),
          ],
          'most_recent_donor' => [
            array_merge([
              'location_type_id' => 'Home',
              'is_primary' => 0,
            ], $locationParams3),
            array_merge([
              'location_type_id' => 'Mailing',
              'is_primary' => 1,
            ], $locationParams2),
          ],
          'expected_hook' => array_merge($additionalExpected, [
            array_merge([
              'location_type_id' => 'Mailing',
              'is_primary' => 1,
            ], $locationParams2),
            array_merge([
              'location_type_id' => 'Home',
              'is_primary' => 0,
            ], $locationParams3),
          ]),
        ],
      ],
      'duplicate_home_address_on_one_contact' => [
        'duplicate_home_address_on_one_contact' => [
          'fix_required_for_reverse' => 1,
          'comment' => 'our code needs an update as an extra 1 is being kept'
            . ' this is not an issue at the moment as it only happens in reverse from the'
            . 'form merge - where we do not intervene',
          'merged' => 1,
          'skipped' => 0,
          'is_major_gifts' => 0,
          'entity' => $entity,
          'earliest_donor' => [
            array_merge([
              'location_type_id' => 'Home',
              'is_primary' => 0,
            ], $locationParams1),
          ],
          'most_recent_donor' => [
            array_merge([
              'location_type_id' => 'Home',
              'is_primary' => 1,
            ], $locationParams1),
            array_merge([
              'location_type_id' => 'Home',
              'is_primary' => 0,
            ], $locationParams1),
          ],
          'expected_hook' => array_merge($additionalExpected, [
            array_merge([
              'location_type_id' => 'Home',
              'is_primary' => 1,
            ], $locationParams1),
          ]),
        ],
      ],
      'duplicate_home_address_on_one_contact_second_primary' => [
        'duplicate_home_address_on_one_contact_second_primary' => [
          'fix_required_for_reverse' => 1,
          'comment' => 'our code needs an update as an extra 1 is being kept'
            . ' this is not an issue at the moment as it only happens in reverse from the'
            . 'form merge - where we do not intervene',
          'merged' => 1,
          'skipped' => 0,
          'is_major_gifts' => 0,
          'entity' => $entity,
          'earliest_donor' => [
            array_merge([
              'location_type_id' => 'Home',
              'is_primary' => 0,
            ], $locationParams1),
          ],
          'most_recent_donor' => [
            array_merge([
              'location_type_id' => 'Home',
              'is_primary' => 0,
            ], $locationParams1),
            array_merge([
              'location_type_id' => 'Home',
              'is_primary' => 1,
            ], $locationParams1),
          ],
          'expected_hook' => array_merge($additionalExpected, [
            array_merge([
              'location_type_id' => 'Home',
              'is_primary' => 1,
            ], $locationParams1),
          ]),
        ],
      ],
      'duplicate_mixed_address_on_one_contact' => [
        'duplicate_mixed_address_on_one_contact' => [
          'merged' => 1,
          'skipped' => 0,
          'comment' => 'We want to be sure we still have a primary. The duplicate (Home) address is squashed',
          'is_major_gifts' => 0,
          'entity' => $entity,
          'earliest_donor' => [
            array_merge([
              'location_type_id' => 'Home',
              'is_primary' => 0,
            ], $locationParams1),
          ],
          'most_recent_donor' => [
            array_merge([
              'location_type_id' => 'Main',
              'is_primary' => 1,
            ], $locationParams1),
            array_merge([
              'location_type_id' => 'Home',
              'is_primary' => 0,
            ], $locationParams1),
          ],
          'expected_hook' => array_merge($additionalExpected, [
            array_merge([
              'location_type_id' => 'Main',
              'is_primary' => 1,
            ], $locationParams1),
          ]),
        ],
      ],
      'duplicate_mixed_address_on_one_contact_second_primary' => [
        'duplicate_mixed_address_on_one_contact_second_primary' => [
          'comment' => 'check we do not lose the primary. The home address is deleted as it matches the (main) Primary.',
          'merged' => 1,
          'skipped' => 0,
          'is_major_gifts' => 0,
          'entity' => $entity,
          'earliest_donor' => [
            array_merge([
              'location_type_id' => 'Home',
              'is_primary' => 0,
            ], $locationParams1),
          ],
          'most_recent_donor' => [
            array_merge([
              'location_type_id' => 'Home',
              'is_primary' => 0,
            ], $locationParams1),
            array_merge([
              'location_type_id' => 'Main',
              'is_primary' => 1,
            ], $locationParams1),
          ],
          'expected_hook' => array_merge($additionalExpected, [
            array_merge([
              'location_type_id' => 'Main',
              'is_primary' => 1,
            ], $locationParams1),
          ]),
        ],
      ],
    ];
  }


  /**
   * Clean up previous runs.
   *
   * Also get rid of the nest.
   */
  protected function doDuckHunt() {
    \CRM_Core_DAO::executeQuery("
      DELETE c, e
      FROM civicrm_contact c
      LEFT JOIN civicrm_email e ON e.contact_id = c.id
      WHERE display_name = 'Donald Duck' OR email = 'the_don@duckland.com'");
    \CRM_Core_DAO::executeQuery('DELETE FROM civicrm_prevnext_cache');
  }

  /**
   * Create contribution.
   *
   * @param array $params
   *   Array of parameters.
   *
   * @return int
   *   id of created contribution
   * @throws \CRM_Core_Exception
   */
  public function contributionCreate($params): int {
    $params = array_merge([
      'receive_date' => date('Ymd'),
      'total_amount' => 100.00,
      'fee_amount' => 5.00,
      'net_amount' => 95.00,
      'financial_type_id' => 1,
      'payment_instrument_id' => 1,
      'non_deductible_amount' => 10.00,
      'contribution_status_id' => 1,
    ], $params);

    return (int) $this->callAPISuccess('contribution', 'create', $params)['id'];
  }

  /**
   * Create a test duck.
   *
   * @param array $extraParams
   *   Any overrides to be added to the create call.
   *
   * @return int
   * @throws \CRM_Core_Exception
   */
  public function breedDuck($extraParams = []): int {
    $contact = $this->callAPISuccess('Contact', 'create', array_merge([
      'contact_type' => 'Individual',
      'first_name' => 'Donald',
      'last_name' => 'Duck',
      'api.email.create' => [
        'email' => 'the_don@duckland.com',
        'location_type_id' => 'Work',
      ],
    ], $extraParams));
    return (int) $contact['id'];
  }

  /**
   * Add some donations to our ducks
   *
   * @param bool $isReverse
   *   Reverse which duck is the most recent donor? ie. make duck 1 more recent.
   *
   * @throws \CRM_Core_Exception
   */
  private function giveADuckADonation($isReverse) {
    $this->callAPISuccess('Contribution', 'create', [
      'contact_id' => $isReverse ? $this->contactID2 : $this->contactID,
      'financial_type_id' => 'Cash',
      'total_amount' => 10,
      'currency' => 'USD',
      // Should cause 'is_2014 to be true.
      'receive_date' => '2014-08-04',
      wmf_civicrm_get_custom_field_name('original_currency') => 'NZD',
      wmf_civicrm_get_custom_field_name('original_amount') => 8,
    ]);
    $this->callAPISuccess('Contribution', 'create', [
      'contact_id' => $isReverse ? $this->contactID : $this->contactID2,
      'financial_type_id' => 'Cash',
      'total_amount' => 5,
      'currency' => 'USD',
      // Should cause 'is_2012_donor to be true.
      'receive_date' => '2013-01-04',
    ]);
    $this->callAPISuccess('Contribution', 'create', [
      'contact_id' => $isReverse ? $this->contactID : $this->contactID2,
      'financial_type_id' => 'Cash',
      'total_amount' => 9,
      'currency' => 'USD',
      'source' => 'NZD 20',
      // Should cause 'is_2015_donor to be true.
      'receive_date' => '2016-04-04',
    ]);
  }

  /**
   * Breed a donor duck.
   *
   * @param int $contactID
   * @param array $duckOverrides
   * @param bool $isLatestDonor
   *
   * @throws \CRM_Core_Exception
   */
  protected function breedGenerousDuck($contactID, $duckOverrides, $isLatestDonor) {
    $params = array_merge(['id' => $contactID], $duckOverrides);
    $this->breedDuck($params);
    $this->callAPISuccess('Contribution', 'create', [
      'contact_id' => $contactID,
      'financial_type_id' => 'Donation',
      'total_amount' => 5,
      'receive_date' => $isLatestDonor ? '2018-09-08' : '2015-12-20',
      'contribution_status_id' => 'Completed',
    ]);
  }

  /**
   * Emulate a logged in user since certain functions use that.
   * value to store a record in the DB (like activity)
   * CRM-8180
   *
   * @return int
   *   Contact ID of the created user.
   * @throws \CRM_Core_Exception
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
      'Access CiviCRM',
      'Administer CiviCRM',
    ];
    return $contactID;
  }

  /**
   * Asset the specified fields match those on the given contact.
   *
   * @param int $contactID
   * @param array $expected
   *
   * @throws \API_Exception
   */
  protected function assertContactValues($contactID, $expected) {
    $contact = Contact::get()->setSelect(
      array_keys($expected)
    )->addWhere('id', '=', $contactID)->execute()->first();

    foreach ($expected as $key => $value) {
      $this->assertEquals($value, $contact[$key], "wrong value for $key");
    }
  }

  /**
   * Assert exactly one of the entities arraay hhas a key is_primary equal to 1.
   *
   * @param array $entities
   */
  protected function assertOnePrimary($entities) {
    $primaryCount = 0;
    foreach ($entities as $entity) {
      $primaryCount += $entity['is_primary'];
    }
    $this->assertEquals(1, $primaryCount);
  }

}

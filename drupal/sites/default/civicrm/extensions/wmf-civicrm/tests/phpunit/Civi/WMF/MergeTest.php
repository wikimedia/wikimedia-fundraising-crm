<?php

namespace Civi\WMF;

use Civi\Api4\Address;
use Civi\Api4\Contact;
use Civi\Api4\CustomField;
use Civi\Api4\Email;
use Civi\Api4\OptionValue;
use Civi\Test;
use Civi\Test\Api3TestTrait;
use Civi\Test\CiviEnvBuilder;
use Civi\Test\HeadlessInterface;
use Civi\Test\HookInterface;
use PHPUnit\Framework\TestCase;

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
class MergeTest extends TestCase implements HeadlessInterface, HookInterface {
  use Test\EntityTrait;
  use Api3TestTrait;

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
  protected $initialContactCount;

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
   * @throws \Exception
   */
  public function setUp(): void {
    parent::setUp();
    $this->adminUserID = $this->imitateAdminUser();
    $this->initialContactCount = $this->callAPISuccessGetCount('Contact', ['is_deleted' => '']);

    $this->contactID = $this->breedDuck(['Communication.do_not_solicit' => 0], 'first_duck');
    $this->contactID2 = $this->breedDuck(['Communication.do_not_solicit' => 1], 'second_duck');
    $locationTypes = array_flip(\CRM_Core_BAO_Address::buildOptions('location_type_id', 'validate'));
    $types = [];
    foreach (['Main', 'Other', 'Home', 'Mailing', 'Billing', 'Work'] as $type) {
      $types[] = $locationTypes[$type];
    }

    \Civi::settings()->set('deduper_location_priority_order', $types);
    \Civi::settings()->set('deduper_resolver_email', 'preferred_contact_with_re-assign');
    \Civi::settings()->set('deduper_resolver_field_prefer_preferred_contact', ['source', $this->getCustomFieldString('opt_in'), 'preferred_language']);
    \Civi::settings()->set('deduper_resolver_preferred_contact_resolution', ['most_recent_contributor']);
    \Civi::settings()->set('deduper_resolver_preferred_contact_last_resort', 'most_recently_created_contact');
    \Civi::settings()->set('deduper_resolver_custom_groups_to_skip', ['wmf_donor']);
  }

  /**
   * Get the api v3 style string for the custom field.
   *
   * @param string $name e.g custom_3
   *
   * @return string
   * @throws \CRM_Core_Exception
   */
  public function getCustomFieldString(string $name): string {
    return 'custom_' . CustomField::get(FALSE)
      ->setSelect(['id'])
      ->addWhere('name', '=', $name)
      ->execute()
      ->first()['id'];
  }

  /**
   * Clean up after test.
   *
   * @throws \CRM_Core_Exception
   */
  public function tearDown(): void {
    $this->callAPISuccess('Contribution', 'get', [
      'contact_id' => ['IN' => [$this->contactID, $this->contactID2]],
      'api.Contribution.delete' => 1,
    ]);
    OptionValue::delete(FALSE)
      ->addWhere('option_group_id:name', '=', 'languages')
      ->addWhere('name', '=', 'en')
      ->execute();
    \CRM_Core_Session::singleton()->set('userID', NULL);
    $this->callAPISuccess('Contact', 'delete', ['id' => $this->contactID, 'skip_undelete' => TRUE]);
    $this->callAPISuccess('Contact', 'delete', ['id' => $this->contactID2, 'skip_undelete' => TRUE]);
    $this->doDuckHunt();
    $this->callAPISuccess('Job', 'process_batch_merge', ['mode' => 'safe']);
    parent::tearDown();
    $this->assertEquals($this->initialContactCount, $this->callAPISuccessGetCount('Contact', ['is_deleted' => '']), 'contact cleanup incomplete');
  }

  /**
   * Test that the merge hook causes our custom fields to not be treated as conflicts.
   *
   * We also need to check the custom data fields afterwards.
   *
   * @param bool $isReverse
   *   Should we reverse the contact order for more test cover.
   *
   * @throws \CRM_Core_Exception
   *
   * @dataProvider isReverse
   */
  public function testMergeHook(bool $isReverse): void {
    $this->giveADuckADonation($isReverse);
    $this->assertContactValues($isReverse ? $this->contactID2 : $this->contactID, [
      'wmf_donor.lifetime_usd_total' => 10,
    ]);

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
      'wmf_donor.last_donation_date' => '2024-07-04 00:00:00',
      'wmf_donor.first_donation_usd' => 5,
      'wmf_donor.first_donation_date' => '2013-01-04 00:00:00',
      'wmf_donor.date_of_largest_donation' => '2023-08-04 00:00:00',
      'wmf_donor.number_donations' => 3,
      'wmf_donor.total_2022_2023' => 0,
      'wmf_donor.total_2023_2024' => 10,
    ]);

    // Now lets check the one to be deleted has a do_not_solicit = 0.
    $this->callAPISuccess('Contact', 'create', [
      'contact_type' => 'Individual',
      'first_name' => 'Donald',
      'last_name' => 'Duck',
      'email_primary.email' => 'the_don@duckland.com',
      'version' => 4,
      'Communication.do_not_solicit' => 0,
    ]);
    $result = $this->callAPISuccess('Job', 'process_batch_merge', [
      'criteria' => ['contact' => ['id' => $this->contactID]],
    ]);
    $this->assertCount(1, $result['values']['merged']);
    $this->assertContactValues($this->contactID, [
      'wmf_donor.lifetime_usd_total' => 24,
      'Communication.do_not_solicit' => TRUE,
    ]);
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
   *   Should we reverse the contact order for more test cover?
   *
   * @dataProvider isReverse
   *
   * @throws \CRM_Core_Exception
   * @throws \CRM_Core_Exception
   */
  public function testMergeEmailNonPrimary($isReverse): void {
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
    $emails = Email::get()->addSelect('*')->addWhere('contact_id', '=', $this->contactID)->setOrderBy(['is_primary' => 'DESC'])->execute();
    $this->assertCount(2, $emails);
    $primary = $emails->first();
    $this->assertEquals('better_duck@duckland.com', $primary['email']);
    $this->assertEquals(TRUE, $primary['is_primary']);
    $this->assertOnePrimary((array) $emails);
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
   * Although a bit tangential we test calculations on deleting a contribution at the end.
   *
   * @throws \CRM_Core_Exception
   * @throws \CRM_Core_Exception
   */
  public function testMergeEndowmentCalculation(): void {
    $this->callAPISuccess('Contribution', 'create', [
      'contact_id' => $this->contactID,
      'financial_type_id:name' => 'Endowment Gift',
      'total_amount' => 10,
      'currency' => 'USD',
      'version' => 4,
      'receive_date' => '2024-08-04',
      'contribution_extra.original_currency' => 'NZD',
      'contribution_extra.original_amount' => 8,
    ]);
    $cashJob = $this->callAPISuccess('Contribution', 'create', [
      'contact_id' => $this->contactID2,
      'financial_type_id:name' => 'Cash',
      'total_amount' => 5,
      'version' => 4,
      'currency' => 'USD',
      'receive_date' => '2023-01-04',
    ]);

    $this->callAPISuccess('Contribution', 'create', [
      'contact_id' => $this->contactID2,
      'financial_type_id:name' => 'Endowment Gift',
      'total_amount' => 7,
      'currency' => 'USD',
      'version' => 4,
      'receive_date' => '2025-01-04',
    ]);

    $this->assertContactValues($this->contactID, [
      'wmf_donor.lifetime_usd_total' => 0,
    ]);

    $this->assertContactValues($this->contactID2, [
      'wmf_donor.lifetime_usd_total' => 5,
    ]);

    $result = $this->callAPISuccess('Job', 'process_batch_merge', [
      'criteria' => ['contact' => ['id' => ['IN' => [$this->contactID, $this->contactID2]]]],
    ]);
    $this->assertCount(1, $result['values']['merged']);
    $this->assertContactValues($this->contactID, [
      'wmf_donor.lifetime_usd_total' => 5,
      'wmf_donor.last_donation_amount' => 5,
      'wmf_donor.last_donation_currency' => 'USD',
      'wmf_donor.last_donation_usd' => 5,
      'wmf_donor.last_donation_date' => '2023-01-04 00:00:00',
      'wmf_donor.total_2023_2024' => 0,
    ]);

    $this->callAPISuccess('Contribution', 'delete', ['id' => $cashJob['id']]);
    $this->assertContactValues($this->contactID, [
      'wmf_donor.lifetime_usd_total' => 0,
      'wmf_donor.last_donation_amount' => 0,
      'wmf_donor.last_donation_currency' => '',
      'wmf_donor.last_donation_usd' => 0,
      'wmf_donor.last_donation_date' => '',
      'wmf_donor.total_2023_2024' => 0,
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
   *  1) (Fill data) both contacts have the same primary with the same location (Home). The first has an additional
   * address (Mailing). Outcome: common primary is retained as the Home address & additional Mailing address is
   * retained on the merged contact. Notes: our behaviour is the same as core.
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
   */
  public function testBatchMergesAddressesHook(array $dataSet): void {
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
  public function testBatchMergesAddressesHookLowerIDMoreRecentDonor(array $dataSet): void {
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
   */
  public function testBatchMergeConflictOnHold(): void {
    $emailDuck1 = $this->callAPISuccess('Email', 'get', ['contact_id' => $this->contactID, 'return' => 'id']);
    $this->giveADuckADonation(FALSE);
    $this->callAPISuccess('Email', 'create', ['id' => $emailDuck1['id'], 'on_hold' => 1]);
    $result = $this->callAPISuccess('Job', 'process_batch_merge', ['mode' => 'safe']);
    $this->assertCount(0, $result['values']['skipped']);
    $this->assertCount(1, $result['values']['merged']);
    $email = $this->callAPISuccessGetSingle('Email', ['contact_id' => $this->contactID]);
    $this->assertEquals(1, $email['on_hold']);

    $this->callAPISuccess('Email', 'create', ['id' => $email['id'], 'on_hold' => 0]);
    $duck2 = $this->breedDuck(['Communication.do_not_solicit' => 1]);
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
   * Currently, is_opt_out have logic as if any contact opt out, then we mark it opt out, doesn't matter if it's a
   * preferred contact.
   */
  public function testBatchMergeConflictCommunicationPreferences(): void {
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
   */
  public function testBatchMergeConflictPreferredLanguage($dataSet): void {
    // Can't use api if we are trying to use invalid data.

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
   */
  public function testBatchMergeConflictDifferentPreferredLanguage($language1, $language2): void {
    // Can't use api if we are trying to use invalid data.
    $this->contributionCreate(['contact_id' => $this->contactID, 'receive_date' => '2010-01-01', 'invoice_id' => 1, 'trxn_id' => 1]);
    $this->contributionCreate(['contact_id' => $this->contactID2, 'receive_date' => '2012-01-01', 'invoice_id' => 2, 'trxn_id' => 2]);

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
   */
  public function testBatchMergeConflictDifferentPreferredLanguageReverse($language1, $language2) {
    // Can't use api if we are trying to use invalid data.
    $this->contributionCreate(['contact_id' => $this->contactID, 'receive_date' => '2012-01-01', 'invoice_id' => 1, 'trxn_id' => 1]);
    $this->contributionCreate(['contact_id' => $this->contactID2, 'receive_date' => '2010-01-01', 'invoice_id' => 2, 'trxn_id' => 2]);

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
      [['languages' => ['en', 'en_US'], 'is_conflict' => FALSE, 'selected' => 'en_US']],
      [['languages' => ['en_XX', 'en_US'], 'is_conflict' => FALSE, 'selected' => 'en_US']],
      [['languages' => ['en_NZ', 'en_US'], 'is_conflict' => FALSE, 'selected' => 'en_US']],
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
   */
  public function testBatchMergeConflictSource(): void {
    $this->primpDuck('first_duck', ['source' => 'egg']);
    $this->primpDuck('second_duck', ['source' => 'chicken']);
    $result = $this->callAPISuccess('Job', 'process_batch_merge', ['mode' => 'safe']);
    $this->assertCount(0, $result['values']['skipped']);
    $this->assertCount(1, $result['values']['merged']);
  }

  /**
   * Test that we keep the opt-in from the most recent donor.
   *
   * The handling for this is in the dedupe tools. Testing in our code checks
   * our settings have been added.
   *
   * @param bool $isReverse
   *
   * @dataProvider isReverse
   */
  public function testBatchMergeConflictOptIn(bool $isReverse) {
    $this->breedGenerousDuck('first_duck', ['Communication.opt_in' => 1], !$isReverse);
    $this->breedGenerousDuck('second_duck', ['Communication.opt_in' => 0], $isReverse);
    $result = $this->callAPISuccess('Job', 'process_batch_merge', ['mode' => 'safe']);
    $this->assertCount(0, $result['values']['skipped'], 'skipped count is wrong');
    $this->assertCount(1, $result['values']['merged'], 'merged count is wrong');
    $contact = $this->callAPISuccessGetSingle('Contact', ['id' => $this->ids['Contact']['first_duck'], 'return' => 'Communication.opt_in', 'version' => 4]);
    $this->assertEquals($isReverse ? 0 : 1, $contact['Communication.opt_in']);
  }

  /**
   * Test that whitespace conflicts are resolved.
   *
   * Bug T146946
   *
   * @throws \CRM_Core_Exception
   */
  public function testBatchMergeResolvableConflictWhiteSpace() {
    $this->primpDuck('first_duck', ['first_name' => 'alter ego']);
    $this->primpDuck('second_duck', ['first_name' => 'alterego']);
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
    $this->primpDuck('first_duck', ['first_name' => 'alter. ego']);
    $this->primpDuck('second_duck', ['first_name' => 'alterego']);
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
    $this->primpDuck('first_duck', ['first_name' => 'alter. ego']);
    $this->primpDuck('second_duck', ['first_name' => '1']);
    $result = $this->callAPISuccess('Job', 'process_batch_merge', ['mode' => 'safe']);
    $this->assertCount(1, $result['values']['merged']);
    $contact = $this->callAPISuccessGetSingle('Contact', ['email' => 'the_don@duckland.com']);
    $this->assertEquals('alter ego', $contact['first_name']);
  }

  /**
   * Test that having city set to NULL does not cause problems.
   *
   * A recent upstream fix started returning NULL fields as conflicts which caused
   * problems with a type hint.
   *
   * @throws \CRM_Core_Exception
   */
  public function testBatchMergeAddressNullCity(): void {
    $this->breedGenerousDuck('first_duck', ['address_primary.city' => 'Duckville', 'address_primary.postal_code' => 90210, 'address_primary.country_id.name' => 'Mexico'], FALSE);
    $this->breedGenerousDuck('second_duck', ['address_primary.address_data.address_update_date' => '2024-02-19', 'address_primary.country_id.name' => 'New Zealand'], TRUE);

    $address = Address::get(FALSE)
      ->addWhere('contact_id', '=', $this->ids['Contact']['second_duck'])
      ->setSelect(['city', 'country_id:name', 'country_id.name', 'address_data.address_update_date'])
      ->execute()->single();

    $this->callAPISuccess('Contact', 'merge', [
      'mode' => 'safe',
      'to_remove_id' => $this->ids['Contact']['second_duck'],
      'to_keep_id' => $this->ids['Contact']['first_duck'],
    ]);
    $address = Address::get(FALSE)
      ->addWhere('contact_id', '=', $this->ids['Contact']['first_duck'])
      ->setSelect(['city', 'country_id:name', 'country_id.name', 'address_data.address_update_date'])
      ->execute()->single();
    $this->assertEquals(NULL, $address['city']);
    $this->assertEquals('NZ', $address['country_id:name']);
    $this->assertEquals('2024-02-19', $address['address_data.address_update_date']);
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
      'address_source.address_update_date' => '2024-02-19',
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
   */
  public function testBatchMergeUnResolvableConflictCityLooksCountryishWithCounty(): void {
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
   */
  public function testBatchMergeUnResolvableConflictCityLooksCountryishNoCountry():void {
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
   */
  public function testBatchMergeConflictNameCasingOrgs(): void {
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
   * Test SlumDuck millionaire wins the postal code lottery.
   *
   * Addresses a bug where the presence of a conflicting postal
   * code results in the wrong address being kept.
   *
   * https://phabricator.wikimedia.org/T330231
   */
  public function testKeepCorrectAddress(): void {
    $toKeepID = $this->breedDuck();
    $toRemoveID = $this->breedDuck();
    $this->callAPISuccess('Email', 'create', [
      'contact_id' => $toRemoveID,
      'email' => 'duck@example.com',
    ]);
    $this->callAPISuccess('Email', 'create', [
      'contact_id' => $toKeepID,
      'email' => 'duck@example.com',
    ]);
    $this->callAPISuccess('Contribution', 'create', [
      'contact_id' => $toKeepID,
      'financial_type_id' => 1,
      'receive_date' => '2023-04-01',
      'total_amount' => 90,
    ]);
    $this->callAPISuccess('Contribution', 'create', [
      'contact_id' => $toRemoveID,
      'financial_type_id' => 1,
      'receive_date' => '2022-04-01',
      'total_amount' => 90,
    ]);
    $this->callAPISuccess('Address', 'create', [
      'contact_id' => $toKeepID,
      'street_address' => 'Duck Manor',
      'location_type_id' => 1,
      'postal_code' => '8567',
    ]);
    $this->callAPISuccess('Address', 'create', [
      'contact_id' => $toRemoveID,
      'street_address' => 'Duck Slum',
      'location_type_id' => 1,
      'postal_code' => '567',
    ]);
    $this->callAPISuccess('Contact', 'merge', [
      'mode' => 'aggressive',
      'to_keep_id' => $toKeepID,
      'to_remove_id' => $toRemoveID,
    ]);
    $contact = $this->callAPISuccess('Contact', 'get', [
      'id' => [
        'IN' => [
          $toKeepID,
          $toRemoveID,
        ],
      ],
    ]);
    $this->assertEquals('Duck Manor', $contact['values'][$toKeepID]['street_address']);
  }

  /**
   * Test that when two contacts are merged, the state part of the address from
   * the merged contact is retained.
   *
   * This test assigns different addresses with different states to two
   * existing contacts created in setUp(), merges them, and checks that the
   * state from the merged contact's address is retained in the remaining
   * contact.
   */
  public function testMergeRetainsStateInAddress(): void {
    // Add addresses to existing contacts
    $this->callAPISuccess('Address', 'create', [
      'contact_id' => $this->contactID,
      'location_type_id' => 1,
      'street_address' => '123',
      'city' => 'Springfield',
      'state_province' => 'NY',
      'country' => 'US',
      'is_primary' => 1,
    ]);

    $address1 = $this->callAPISuccessGetSingle('Address', [
      'contact_id' => $this->contactID,
      'is_primary' => 1,
    ]);

    $this->callAPISuccess('Address', 'create', [
      'contact_id' => $this->contactID2,
      'location_type_id' => 1,
      'street_address' => '123 Oak Avenue',
      'city' => 'Springfield',
      'state_province' => 'NY',
      'country_id' => 'US',
      'is_primary' => 1,
      'is_billing' => 0,
    ]);

    $address2 = $this->callAPISuccessGetSingle('Address', [
      'contact_id' => $this->contactID2,
      'is_primary' => 1,
    ]);

    // Merge the two contacts
    $this->callAPISuccess('Contact', 'merge', [
      'to_keep_id' => $this->contactID,
      'to_remove_id' => $this->contactID2,
      'mode' => 'aggressive',
    ]);

    // Fetch the primary address of the remaining contact
    $address = $this->callAPISuccessGetSingle('Address', [
      'contact_id' => $this->contactID,
      'is_primary' => 1,
    ]);

    // Pull the state name using the state_province_id
    $state = \CRM_Core_PseudoConstant::stateProvinceAbbreviation($address['state_province_id']);

    // Confirm that the state from the merged/deleted contact ($this->contactID2) is retained
    // as it was added most recently.
    $this->assertEquals('NY', $state, 'The state from the merged address was not retained.');
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
          'expected_hook' => array_merge($additionalExpected,
            [array_merge([
              'is_primary' => 0,
            ], $locationParams1)],
            [array_merge([
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
          'expected_hook' => array_merge($additionalExpected,
            [array_merge([
              'location_type_id' => 'Main',
              'is_primary' => 0,
            ], $locationParams1),
            ],
            [
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
              'location_type_id' => 'Main',
              'is_primary' => 0,
            ], $locationParams1),
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
              'location_type_id' => 'Main',
              'is_primary' => 0,
            ], $locationParams1),
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
  protected function doDuckHunt(): void {
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
   */
  public function contributionCreate(array $params): int {
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
   * @param string $identifier
   *
   * @return int
   */
  public function breedDuck(array $extraParams = [], string $identifier = 'duck'): int {
    $contact = $this->createTestEntity('Contact', array_merge([
      'contact_type' => 'Individual',
      'first_name' => 'Donald',
      'last_name' => 'Duck',
      'email_primary.email' => 'the_don@duckland.com',
      'email_primary.location_type_id:name' => 'Work',
    ], $extraParams), $identifier);
    return (int) $contact['id'];
  }

  /**
   * @param string $identifier
   * @param array $values
   *
   * @return void
   */
  public function primpDuck(string $identifier, array $values): void {
    try {
      Contact::update(FALSE)
        ->addWhere('id', '=', $this->ids['Contact'][$identifier])
        ->setValues($values)
        ->execute();
    }
    catch (\CRM_Core_Exception $e) {
      $this->fail('Your duck is never gonna get the ribbon ' . $e->getMessage());
    }
  }

  /**
   * Add some donations to our ducks
   *
   * @param bool $isReverse
   *   Reverse which duck is the most recent donor? ie. make duck 1 more recent.
   */
  private function giveADuckADonation(bool $isReverse): void {
    $this->callAPISuccess('Contribution', 'create', [
      'contact_id' => $isReverse ? $this->contactID2 : $this->contactID,
      'financial_type_id' => 'Cash',
      'total_amount' => 10,
      'currency' => 'USD',
      'version' => 4,
      'receive_date' => '2023-08-04',
      'contribution_extra.original_currency' => 'NZD',
      'contribution_extra.original_amount' => 20,
    ]);
    $this->callAPISuccess('Contribution', 'create', [
      'contact_id' => $isReverse ? $this->contactID : $this->contactID2,
      'financial_type_id' => 'Cash',
      'total_amount' => 5,
      'currency' => 'USD',
      'receive_date' => '2013-01-04',
    ]);
    $this->callAPISuccess('Contribution', 'create', [
      'contact_id' => $isReverse ? $this->contactID : $this->contactID2,
      'financial_type_id' => 'Cash',
      'total_amount' => 9,
      'currency' => 'USD',
      'source' => 'NZD 20',
      'receive_date' => '2024-07-04',
    ]);
  }

  /**
   * Breed a donor duck.
   *
   * @param string $identifier
   * @param array $duckOverrides
   * @param bool $isLatestDonor
   *
   */
  protected function breedGenerousDuck(string $identifier, array $duckOverrides, bool $isLatestDonor): void {
    $this->primpDuck($identifier, $duckOverrides);
    $this->createTestEntity('Contribution', [
      'contact_id' => $this->ids['Contact'][$identifier],
      'financial_type_id:name' => 'Donation',
      'total_amount' => 5,
      'receive_date' => $isLatestDonor ? '2018-09-08' : '2015-12-20',
      'contribution_status_id:name' => 'Completed',
    ], 'generous');
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
   * @throws \CRM_Core_Exception
   */
  protected function assertContactValues($contactID, $expected) {
    $contact = Contact::get(FALSE)->setSelect(
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

  /**
   * Check that locations in the test have appropriate numbers of primaries.
   */
  protected function assertPostConditions(): void {
    $this->assertLocationValidity();
  }

  /**
   * Validate that all location entities have exactly one primary.
   *
   * This query takes about 2 minutes on a DB with 10s of millions of contacts.
   */
  public function assertLocationValidity(): void {
    $this->assertEquals(0, \CRM_Core_DAO::singleValueQuery('SELECT COUNT(*) FROM
  (SELECT a1.contact_id
  FROM civicrm_address a1
    LEFT JOIN civicrm_address a2 ON a1.id <> a2.id AND a2.is_primary = 1
    AND a1.contact_id = a2.contact_id
  WHERE
    a1.is_primary = 1
    AND a2.id IS NOT NULL
    AND a1.contact_id IS NOT NULL
  UNION
  SELECT a1.contact_id
  FROM civicrm_address a1
         LEFT JOIN civicrm_address a2 ON a1.id <> a2.id AND a2.is_primary = 1
    AND a1.contact_id = a2.contact_id
  WHERE a1.is_primary = 0
    AND a2.id IS NULL
    AND a1.contact_id IS NOT NULL
  UNION
  SELECT a1.contact_id
  FROM civicrm_email a1
         LEFT JOIN civicrm_email a2 ON a1.id <> a2.id AND a2.is_primary = 1
    AND a1.contact_id = a2.contact_id
  WHERE
      a1.is_primary = 1
    AND a2.id IS NOT NULL
    AND a1.contact_id IS NOT NULL
  UNION
  SELECT a1.contact_id
  FROM civicrm_email a1
         LEFT JOIN civicrm_email a2 ON a1.id <> a2.id AND a2.is_primary = 1
    AND a1.contact_id = a2.contact_id
  WHERE a1.is_primary = 0
    AND a2.id IS NULL
    AND a1.contact_id IS NOT NULL
  UNION
  SELECT a1.contact_id
  FROM civicrm_phone a1
         LEFT JOIN civicrm_phone a2 ON a1.id <> a2.id AND a2.is_primary = 1
    AND a1.contact_id = a2.contact_id
  WHERE
      a1.is_primary = 1
    AND a2.id IS NOT NULL
    AND a1.contact_id IS NOT NULL
  UNION
  SELECT a1.contact_id
  FROM civicrm_phone a1
         LEFT JOIN civicrm_phone a2 ON a1.id <> a2.id AND a2.is_primary = 1
    AND a1.contact_id = a2.contact_id
  WHERE a1.is_primary = 0
    AND a2.id IS NULL
    AND a1.contact_id IS NOT NULL
  UNION
  SELECT a1.contact_id
  FROM civicrm_im a1
         LEFT JOIN civicrm_im a2 ON a1.id <> a2.id AND a2.is_primary = 1
    AND a1.contact_id = a2.contact_id
  WHERE
      a1.is_primary = 1
    AND a2.id IS NOT NULL
    AND a1.contact_id IS NOT NULL
  UNION
  SELECT a1.contact_id
  FROM civicrm_im a1
         LEFT JOIN civicrm_im a2 ON a1.id <> a2.id AND a2.is_primary = 1
    AND a1.contact_id = a2.contact_id
  WHERE a1.is_primary = 0
    AND a2.id IS NULL
    AND a1.contact_id IS NOT NULL
  UNION
  SELECT a1.contact_id
  FROM civicrm_openid a1
         LEFT JOIN civicrm_openid a2 ON a1.id <> a2.id AND a2.is_primary = 1
    AND a1.contact_id = a2.contact_id
  WHERE (a1.is_primary = 1 AND a2.id IS NOT NULL)
  UNION
  SELECT a1.contact_id
  FROM civicrm_openid a1
         LEFT JOIN civicrm_openid a2 ON a1.id <> a2.id AND a2.is_primary = 1
    AND a1.contact_id = a2.contact_id
  WHERE
      a1.is_primary = 1
    AND a2.id IS NOT NULL
    AND a1.contact_id IS NOT NULL
  UNION
  SELECT a1.contact_id
  FROM civicrm_openid a1
         LEFT JOIN civicrm_openid a2 ON a1.id <> a2.id AND a2.is_primary = 1
    AND a1.contact_id = a2.contact_id
  WHERE a1.is_primary = 0
    AND a2.id IS NULL
    AND a1.contact_id IS NOT NULL) AS primary_descrepancies
      '));
  }

}

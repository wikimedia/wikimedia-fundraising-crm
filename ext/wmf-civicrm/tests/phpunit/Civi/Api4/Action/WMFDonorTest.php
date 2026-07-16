<?php

namespace Civi\Api4\Action;

use Civi\Api4\Contact;
use Civi\Api4\Contribution;
use Civi\Api4\ContributionRecur;
use Civi\Api4\Generic\Result;
use Civi\Api4\WMFDonor;
use Civi\Api4\WMFDonorHistory;
use Civi\WMFHook\CalculatedData;
use Civi\Test;
use Civi\Test\HeadlessInterface;
use Civi\Test\HookInterface;
use Civi\WMFEnvironmentTrait;
use PHPUnit\Framework\TestCase;

/**
 * Test our calculated fields.
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
 * @property array ids
 * @group headless
 */
class WMFDonorTest extends TestCase implements HeadlessInterface, HookInterface {

  use WMFEnvironmentTrait;
  use Test\EntityTrait;

  /**
   * Current date.
   *
   * @var string
   */
  protected string $currentDate;

  public function setUp(): void {
    $this->currentDate = date('Y-m-d');

    // This setTime works for the tests that check the php level calculation but not the triggers.
    \CRM_Utils_Time::setTime($this->currentDate);
    parent::setUp();
  }

  /**
   * Test getFields works for WMFDonor pseudo entity.
   *
   * @throws \CRM_Core_Exception
   */
  public function testWMFDonorGetFields(): void {
    $fields = WMFDonor::getFields(FALSE)->execute()->indexBy('name');
    $this->assertNotEmpty($fields['all_funds_last_donation_date']);
    $this->assertNotEmpty($fields['donor_status_recur_overall']);
  }

  /**
   * Test that we can get WMF Donor calculated fields.
   *
   * @throws \CRM_Core_Exception
   */
  public function testWMFDonorGet(): void {
    $this->createDonor();
    // Select all_funds_last_donation_date only.
    $result = WMFDonor::get(FALSE)
      ->addSelect('all_funds_last_donation_date')
      ->addWhere('id', '=', $this->ids['Contact']['donor'])
      ->execute()->first();
    $this->assertEquals($this->getDate() . ' 00:00:00', $result['all_funds_last_donation_date']);

    // Do not specify fields.
    $result = WMFDonor::get(FALSE)
      ->addWhere('id', '=', $this->ids['Contact']['donor'])
      ->execute()->first();
    $this->assertEquals($this->getDate() . ' 00:00:00', $result['all_funds_last_donation_date']);

    // Specify a field that requires an additional join.
    $result = WMFDonor::get(FALSE)
      ->addSelect('last_donation_usd')
      ->addWhere('id', '=', $this->ids['Contact']['donor'])
      ->execute()->first();
    $this->assertEquals(20000, $result['last_donation_usd']);
  }

  /**
   * Test the insanity that is donor segmentation.
   *
   * @throws \CRM_Core_Exception
   */
  public function testWMFDonorGetSegments(): void {
    $this->createDonor();

    // Specify a field that requires an additional join.
    $result = WMFDonor::get(FALSE)
      ->setDebug(TRUE)
      ->addSelect('donor_segment_id', 'donor_segment_id:label', 'donor_segment_id:description', 'donor_status_id', 'donor_status_id:label')
      ->addWhere('id', 'IN', $this->ids['Contact'])
      ->execute();

    // This shows how to get useful sql for debugging...
    $sql = $result->debug['sql'];
    $this->assertStringContainsString('as donor_segment_id', $sql);
    // Major gifts donor.
    $row = $result->first();
    $this->assertEquals(100, $row['donor_segment_id']);
    $this->assertEquals(50, $row['donor_status_id']);
    $this->assertEquals('Major Donor', $row['donor_segment_id:label']);
    $this->assertEquals('Lapsed', $row['donor_status_id:label']);
    $this->assertStringContainsString('$10,000.00 between ', $row['donor_segment_id:description']);
  }

  /**
   * Test the insanity that is donor segmentation.
   *
   * @throws \CRM_Core_Exception
   */
  public function testWMFDonorGetAnnualRecurSegments(): void {
    $this->createDonor(['total_amount' => 2]);
    $annualDonationDate = date('Y-m-d', strtotime('-8 months', strtotime($this->currentDate)));
    $monthlyDonationDate = date('Y-m-d', strtotime('-7 months', strtotime($this->currentDate)));
    $thirtySixMonthsAgoDate = date('Y-m-d', strtotime('-36 months', strtotime($this->currentDate)));
    $todayDate = $this->currentDate;

    $this->createTestEntity('ContributionRecur', [
      'contact_id' => $this->ids['Contact']['donor'],
      'frequency_unit' => 'year',
      'frequency_interval' => 1,
      'amount' => 99,
      'start_date' => $annualDonationDate,
    ], 'annual');

    $this->createTestEntity('ContributionRecur', [
      'contact_id' => $this->ids['Contact']['donor'],
      'frequency_unit' => 'month',
      'frequency_interval' => 1,
      'amount' => 8,
      'start_date' => $monthlyDonationDate,
    ], 'month');

    $this->createTestEntity('Contribution', [
      'contact_id' => $this->ids['Contact']['donor'],
      'contribution_recur_id' => $this->ids['ContributionRecur']['annual'],
      'total_amount' => 99,
      'receive_date' => $annualDonationDate,
      'financial_type_id:name' => 'Donation',
    ], 'annual');
    // Specify a field that requires an additional join.
    $result = WMFDonor::get(FALSE)
      ->setDebug(TRUE)
      ->addSelect('donor_segment_id', 'donor_segment_id:label', 'donor_segment_id:description', 'donor_status_id', 'donor_status_id:label', 'lifetime_including_endowment')
      ->addWhere('id', 'IN', $this->ids['Contact'])
      ->execute();

    // This shows how to get useful sql for debugging...
    $sql = $result->debug['sql'];
    $this->assertStringContainsString('as donor_segment_id', $sql);
    // Annual recur donor.
    $row = $result->first();
    $this->assertEquals(101, $row['lifetime_including_endowment']);
    $this->assertEquals(450, $row['donor_segment_id']);
    $this->assertEquals(12, $row['donor_status_id']);
    $this->assertEquals('Recurring annual donor', $row['donor_segment_id:label']);
    $this->assertEquals('Active Annual Recurring', $row['donor_status_id:label']);
    $this->assertStringContainsString('has an annual recurring plan that is active or was active in the last 13 months', $row['donor_segment_id:description']);

    // End recurring donation today, segment is still annual recurring, status is now delinquent
    $this->updateRecur(['contribution_status_id:name' => 'Cancelled', 'end_date' => $todayDate], 'annual');

    $result = WMFDonor::get(FALSE)
      ->setDebug(TRUE)
      ->addSelect('donor_segment_id', 'donor_status_id')
      ->addWhere('id', 'IN', $this->ids['Contact'])
      ->execute();
    $row = $result->first();
    $this->assertEquals(450, $row['donor_segment_id']);
    $this->assertEquals(14, $row['donor_status_id']);

    // Cancel recurring donation 8 months ago, segment is still annual recurring, status is now lapsed
    $this->updateRecur(['cancel_date' => $annualDonationDate, 'end_date' => NULL], 'annual');

    $result = WMFDonor::get(FALSE)
      ->setDebug(TRUE)
      ->addSelect('donor_segment_id', 'donor_status_id')
      ->addWhere('id', 'IN', $this->ids['Contact'])
      ->execute();
    $row = $result->first();
    $this->assertEquals(450, $row['donor_segment_id']);
    $this->assertEquals(16, $row['donor_status_id']);

    // Check that major donations prevent the donor from having a recurring status
    // Update all the donor's contributions
    Contribution::update(FALSE)
      ->addValue('receive_date', $todayDate)
      ->addValue('total_amount', 1001)
      ->addWhere('contact_id', '=', $this->ids['Contact']['donor'])
      ->execute();

    $result = WMFDonor::get(FALSE)
      ->setDebug(TRUE)
      ->addSelect('donor_segment_id', 'donor_status_id')
      ->addWhere('id', 'IN', $this->ids['Contact'])
      ->execute();
    $row = $result->first();
    $this->assertEquals(200, $row['donor_segment_id']);
    $this->assertEquals(25, $row['donor_status_id']);

    // Cancel recurring donation 36 months ago, segment now falls out of annual recurring to grassroots plus, status is now deep lapsed
    $this->updateRecur(['cancel_date' => $thirtySixMonthsAgoDate], 'annual');

    // Update all the donor's contributions so they aren't a major donor, three years ago makes sure they fall in fiscal years for deep lapsed
    Contribution::update(FALSE)
      ->addValue('receive_date', $thirtySixMonthsAgoDate)
      ->addValue('total_amount', 99)
      ->addWhere('contact_id', '=', $this->ids['Contact']['donor'])
      ->execute();

    $result = WMFDonor::get(FALSE)
      ->setDebug(TRUE)
      ->addSelect('donor_segment_id', 'donor_status_id')
      ->addWhere('id', 'IN', $this->ids['Contact'])
      ->execute();
    $row = $result->first();
    $this->assertEquals(500, $row['donor_segment_id']);
    $this->assertEquals(60, $row['donor_status_id']);

    $this->createTestEntity('Contribution', [
      'contact_id' => $this->ids['Contact']['donor'],
      'contribution_recur_id' => $this->ids['ContributionRecur']['month'],
      'total_amount' => 8,
      'receive_date' => $monthlyDonationDate,
      'financial_type_id:name' => 'Donation',
    ], 'month');

    // Specify a field that requires an additional join.
    $result = WMFDonor::get(FALSE)
      ->setDebug(TRUE)
      ->addSelect('donor_segment_id', 'donor_segment_id:label', 'donor_segment_id:description', 'donor_status_id', 'donor_status_id:label', 'lifetime_including_endowment')
      ->addWhere('id', 'IN', $this->ids['Contact'])
      ->execute();

    // This shows how to get useful sql for debugging...
    $sql = $result->debug['sql'];
    $this->assertStringContainsString('as donor_segment_id', $sql);
    // Annual recur donor.
    $row = $result->first();
    $this->assertEquals(206, $row['lifetime_including_endowment']);
    $this->assertEquals(400, $row['donor_segment_id']);
    $this->assertEquals(8, $row['donor_status_id']);
    $this->assertEquals('Recurring donor', $row['donor_segment_id:label']);
    $this->assertEquals('Deep lapsed Recurring', $row['donor_status_id:label']);
    $this->assertStringContainsString('has made a monthly recurring donation in last 36 months', $row['donor_segment_id:description']);
  }

  /**
   * Add an active annual and monthly recurring contribution for the donor.
   *
   * Both are In Progress and started well before the current financial year, so
   * each gets an Active/15 recurring donor status.
   *
   * @throws \CRM_Core_Exception
   */
  protected function createActiveRecurringContributions(): void {
    $startDate = date('Y-m-d', strtotime('-2 years', strtotime($this->currentDate)));
    foreach (['year', 'month'] as $frequency) {
      $this->createTestEntity('ContributionRecur', [
        'contact_id' => $this->ids['Contact']['donor'],
        'frequency_unit' => $frequency,
        'frequency_interval' => 1,
        'amount' => 10,
        'contribution_status_id' => 5,
        'start_date' => $startDate,
      ], $frequency);
    }
  }

  /**
   * Test the recurring source table's status fields calculate through get.
   *
   * @throws \CRM_Core_Exception
   */
  public function testWMFDonorGetRecurStatuses(): void {
    $this->createDonor();
    $this->createActiveRecurringContributions();

    $result = WMFDonor::get(FALSE)
      ->addSelect('donor_status_recur_year', 'donor_status_recur_year:label', 'donor_status_recur_month', 'donor_status_recur_overall')
      ->addWhere('id', '=', $this->ids['Contact']['donor'])
      ->execute()->first();

    $this->assertEquals(15, $result['donor_status_recur_year']);
    $this->assertEquals(15, $result['donor_status_recur_month']);
    $this->assertEquals(15, $result['donor_status_recur_overall']);
    $this->assertEquals('Active', $result['donor_status_recur_year:label']);
  }

  /**
   * A get selecting recur fields for a contact with a contribution but no
   * recurring should return the default values for the recur fields.
   *
   * @throws \CRM_Core_Exception
   */
  public function testWMFDonorGetRecurDefaultsWithContribution(): void {
    $this->createDonor();

    $row = WMFDonor::get(FALSE)
      ->addSelect('lifetime_including_endowment', 'donor_status_recur_month', 'donor_status_recur_year', 'donor_status_recur_overall')
      ->addWhere('id', '=', $this->ids['Contact']['donor'])
      ->execute()->first();

    $this->assertEquals(20000, $row['lifetime_including_endowment']);
    $this->assertEquals(95, $row['donor_status_recur_month']);
    $this->assertEquals(95, $row['donor_status_recur_year']);
    $this->assertEquals(95, $row['donor_status_recur_overall']);
  }

  /**
   * Test that a get spanning both source tables merges into a single row.
   *
   * lifetime_including_endowment comes from civicrm_contribution and
   * donor_status_recur_year from civicrm_contribution_recur, so a single row holding
   * both proves the per-table selects are merged by contact.
   *
   * @throws \CRM_Core_Exception
   */
  public function testWMFDonorGetAcrossSourceTables(): void {
    $this->createDonor();
    $this->createActiveRecurringContributions();

    $result = WMFDonor::get(FALSE)
      ->addSelect('lifetime_including_endowment', 'donor_status_recur_year')
      ->addWhere('id', '=', $this->ids['Contact']['donor'])
      ->execute();

    $this->assertCount(1, $result);
    $row = $result->first();
    $this->assertEquals(20000, $row['lifetime_including_endowment']);
    $this->assertEquals(15, $row['donor_status_recur_year']);
  }

  /**
   * Test that an update writes both source tables' columns to wmf_donor.
   *
   * @throws \CRM_Core_Exception
   */
  public function testWMFDonorUpdateRecurStatuses(): void {
    $this->createDonor();
    $this->createActiveRecurringContributions();
    $this->clearWMFDonorData();

    WMFDonor::update(FALSE)
      ->addWhere('id', '=', $this->ids['Contact']['donor'])
      ->setValues(['*' => TRUE])
      ->execute();

    $donor = Contact::get(FALSE)
      ->addWhere('id', '=', $this->ids['Contact']['donor'])
      ->addSelect('wmf_donor.donor_status_recur_year', 'wmf_donor.donor_status_recur_month', 'wmf_donor.donor_status_recur_overall', 'wmf_donor.lifetime_including_endowment')
      ->execute()->first();

    $this->assertEquals(15, $donor['wmf_donor.donor_status_recur_year']);
    $this->assertEquals(15, $donor['wmf_donor.donor_status_recur_month']);
    $this->assertEquals(15, $donor['wmf_donor.donor_status_recur_overall']);
    $this->assertEquals(20000, $donor['wmf_donor.lifetime_including_endowment']);
  }

  /**
   * A recur switching frequency_unit moves its status between the recur fields;
   * the overall status is unaffected by the switch.
   *
   * @throws \CRM_Core_Exception
   */
  public function testWMFDonorRecurFrequencyUnitChange(): void {
    $this->createIndividual([], 'donor');
    $this->createTestEntity('ContributionRecur', [
      'contact_id' => $this->ids['Contact']['donor'],
      'frequency_unit' => 'month',
      'frequency_interval' => 1,
      'amount' => 10,
      'contribution_status_id' => 5,
      'start_date' => date('Y-m-d', strtotime('-2 years', strtotime($this->currentDate))),
    ], 'recur');

    $donor = Contact::get(FALSE)
      ->addWhere('id', '=', $this->ids['Contact']['donor'])
      ->addSelect('wmf_donor.donor_status_recur_month', 'wmf_donor.donor_status_recur_year', 'wmf_donor.donor_status_recur_overall')
      ->execute()->first();
    $this->assertEquals(15, $donor['wmf_donor.donor_status_recur_month']);
    $this->assertEquals(95, $donor['wmf_donor.donor_status_recur_year']);
    $this->assertEquals(15, $donor['wmf_donor.donor_status_recur_overall']);

    $this->updateRecur(['frequency_unit' => 'year'], 'recur');

    $donor = Contact::get(FALSE)
      ->addWhere('id', '=', $this->ids['Contact']['donor'])
      ->addSelect('wmf_donor.donor_status_recur_month', 'wmf_donor.donor_status_recur_year', 'wmf_donor.donor_status_recur_overall')
      ->execute()->first();
    $this->assertEquals(95, $donor['wmf_donor.donor_status_recur_month']);
    $this->assertEquals(15, $donor['wmf_donor.donor_status_recur_year']);
    $this->assertEquals(15, $donor['wmf_donor.donor_status_recur_overall']);
  }

  /**
   * last_recurring_amount_change records the size and date of a recur amount change.
   *
   * Both are update-only fields: nothing is recorded on insert, delete or contact change,
   * an increase or decrease records the amount and its date, and an update that leaves the
   * amount unchanged preserves the previously recorded values.
   *
   * @throws \CRM_Core_Exception
   */
  public function testWMFDonorLastRecurringChange(): void {
    $this->createIndividual([], 'donor');
    $this->createTestEntity('ContributionRecur', [
      'contact_id' => $this->ids['Contact']['donor'],
      'frequency_unit' => 'month',
      'frequency_interval' => 1,
      'amount' => 10,
      'contribution_status_id' => 5,
      'start_date' => date('Y-m-d', strtotime('-2 years', strtotime($this->currentDate))),
    ], 'recur');

    // Nothing is recorded on insert.
    $this->assertNull($this->getStoredDonorField('last_recurring_amount_change'));
    $this->assertNull($this->getStoredDonorField('last_recurring_amount_change_date'));

    $this->updateRecur(['amount' => 20.01], 'recur');
    $this->assertEquals(10.01, $this->getStoredDonorField('last_recurring_amount_change'));
    $this->assertEquals($this->currentDate, $this->getStoredDonorField('last_recurring_amount_change_date'));

    $this->updateRecur(['amount' => 5], 'recur');
    $this->assertEquals(-15.01, $this->getStoredDonorField('last_recurring_amount_change'));
    $this->assertEquals($this->currentDate, $this->getStoredDonorField('last_recurring_amount_change_date'));

    // Set a fixed date to make sure it is preserved
    Contact::update(FALSE)
      ->addWhere('id', '=', $this->ids['Contact']['donor'])
      ->addValue('wmf_donor.last_recurring_amount_change_date', '2020-01-01')
      ->execute();

    // A change that leaves the amount untouched preserves both values.
    $this->updateRecur(['contribution_status_id' => 1], 'recur');
    $this->assertEquals(-15.01, $this->getStoredDonorField('last_recurring_amount_change'));
    $this->assertEquals('2020-01-01', $this->getStoredDonorField('last_recurring_amount_change_date'));
  }

  /**
   * Update one of the test recurs.
   *
   * @param array $values
   * @param string $key
   *
   * @throws \CRM_Core_Exception
   */
  private function updateRecur(array $values, string $key): void {
    $update = ContributionRecur::update(FALSE)
      ->addWhere('id', '=', $this->ids['ContributionRecur'][$key]);
    foreach ($values as $field => $value) {
      $update->addValue($field, $value);
    }
    $update->execute();
  }

  /**
   * Get a stored wmf_donor field for the test donor.
   *
   * @param string $fieldName
   *
   * @return mixed
   * @throws \CRM_Core_Exception
   */
  private function getStoredDonorField(string $fieldName) {
    return Contact::get(FALSE)
      ->addWhere('id', '=', $this->ids['Contact']['donor'])
      ->addSelect("wmf_donor.$fieldName")
      ->execute()->first()["wmf_donor.$fieldName"];
  }

  /**
   * The first write to wmf_donor logs a history row flagging every tracked field.
   *
   * @throws \CRM_Core_Exception
   */
  public function testWMFDonorHistoryLogsInsert(): void {
    $this->createDonor();
    $loggedFields = (new CalculatedData())->getLoggedFields();

    $insertRow = WMFDonorHistory::get(FALSE)
      ->addWhere('entity_id', '=', $this->ids['Contact']['donor'])
      ->addOrderBy('log_id', 'ASC')
      ->execute()->first();

    // The insert of the wmf_donor row populates every tracked field, so all are logged.
    $this->assertEquals(array_values(array_column($loggedFields, 'log_changes')), $insertRow['changed_fields']);
  }

  /**
   * Adding a recurring donation logs only the recur field that its status changes.
   *
   * @throws \CRM_Core_Exception
   */
  public function testWMFDonorHistoryLogsRecurringDonation(): void {
    $this->createDonor();
    $loggedFields = (new CalculatedData())->getLoggedFields();

    $this->createTestEntity('ContributionRecur', [
      'contact_id' => $this->ids['Contact']['donor'],
      'frequency_unit' => 'month',
      'frequency_interval' => 1,
      'amount' => 10,
      'contribution_status_id' => 5,
      'start_date' => date('Y-m-d', strtotime('-2 years', strtotime($this->currentDate))),
    ], 'recur');

    $latest = WMFDonorHistory::get(FALSE)
      ->addWhere('entity_id', '=', $this->ids['Contact']['donor'])
      ->addOrderBy('log_id', 'DESC')
      ->execute()->first();

    $this->assertEqualsCanonicalizing(
      [$loggedFields['donor_status_recur_overall']['log_changes'], $loggedFields['donor_status_recur_month']['log_changes']],
      $latest['changed_fields']
    );
    $this->assertEquals(15, $latest['donor_status_recur_overall']);
    $this->assertEquals(15, $latest['donor_status_recur_month']);
    $this->assertEquals(95, $latest['donor_status_recur_year']);
  }

  /**
   * A write that leaves every tracked field unchanged logs no history row.
   *
   * @throws \CRM_Core_Exception
   */
  public function testWMFDonorHistorySkipsUnchangedUpdate(): void {
    $this->createDonor();
    $countBefore = count(WMFDonorHistory::get(FALSE)
      ->addWhere('entity_id', '=', $this->ids['Contact']['donor'])
      ->execute());

    $this->updateWMFDonorData();

    $countAfter = count(WMFDonorHistory::get(FALSE)
      ->addWhere('entity_id', '=', $this->ids['Contact']['donor'])
      ->execute());
    $this->assertEquals($countBefore, $countAfter);
  }

  /**
   * Test donor_segment_overall and years_consecutive across several giving patterns.
   *
   * Each scenario is
   * [contributions (receive_date => amount), expected segment, expected years_consecutive].
   *
   * @throws \CRM_Core_Exception
   */
  public function testWMFDonorSegmentAndConsecutiveYears(): void {
    $scenarios = [
      // A single year: segment from that one year, streak of 1.
      'single_year' => [['2023-09-01' => 1500], 300, 1],
      // Four consecutive years: the streak counts all four, but the segment window
      // is only the last three, so the older $20,000 (FY2020) is excluded -> 400.
      'four_consecutive' => [['2020-09-01' => 20000, '2021-09-01' => 500, '2022-09-01' => 500, '2023-09-01' => 500], 400, 4],
      // Gap at FY2020 ends the streak at 3; window FY2021-2023 -> 300.
      'gap_breaks_streak' => [['2019-09-01' => 20000, '2021-09-01' => 500, '2022-09-01' => 500, '2023-01-01' => 750, '2024-01-01' => 750], 300, 3],
      // The peak can be any year in the window, not just the last.
      'peak_in_window' => [['2021-09-01' => 100, '2022-09-01' => 12000, '2023-09-01' => 100], 100, 3],
    ];

    foreach ($scenarios as $name => [$contributions]) {
      $this->createIndividual([], $name);
      foreach ($contributions as $date => $amount) {
        $this->createTestEntity('Contribution', [
          'contact_id' => $this->ids['Contact'][$name],
          'receive_date' => $date,
          'total_amount' => $amount,
          'financial_type_id:name' => 'Donation',
        ], $name . '_' . $date);
      }
    }

    $rows = Contact::get(FALSE)
      ->addWhere('id', 'IN', array_values($this->ids['Contact']))
      ->addSelect('id', 'wmf_donor.donor_segment_overall', 'wmf_donor.years_consecutive')
      ->execute()->indexBy('id');

    foreach ($scenarios as $name => [, $expectedSegment, $expectedStreak]) {
      $contactID = $this->ids['Contact'][$name];
      $this->assertEquals($expectedSegment, $rows[$contactID]['wmf_donor.donor_segment_overall'], "Wrong donor_segment_overall for '$name'.");
      $this->assertEquals($expectedStreak, $rows[$contactID]['wmf_donor.years_consecutive'], "Wrong years_consecutive for '$name'.");
    }
  }

  /**
   * donor_segment_overall calculates via ::get or ::update and resolves labels.
   *
   * The trigger path is covered by testWMFDonorSegmentAndConsecutiveYears; this
   * covers the WMFDonor::get() and WMFDonor::update() paths plus :label/:description.
   *
   * @throws \CRM_Core_Exception
   */
  public function testWMFDonorSegmentOverall(): void {
    $segments = [
      // [this year's donation amount, expected segment, expected label, expected description fragment]
      'major' => [12000, 100, 'Major Donor', '$10,000+'],
      'mid_value' => [1500, 300, 'Mid Value Donor', '$1,000+'],
    ];
    foreach ($segments as $name => [$amount]) {
      $this->createIndividual([], $name);
      $this->createTestEntity('Contribution', [
        'contact_id' => $this->ids['Contact'][$name],
        'receive_date' => $this->currentDate,
        'total_amount' => $amount,
        'financial_type_id:name' => 'Donation',
      ], $name);
    }

    $rows = (array) WMFDonor::get(FALSE)
      ->addSelect('donor_segment_overall', 'donor_segment_overall:label', 'donor_segment_overall:description')
      ->addWhere('id', 'IN', array_values($this->ids['Contact']))
      ->execute()->indexBy('id');
    foreach ($segments as $name => [, $expected, $expectedLabel, $expectedDescription]) {
      $contactID = $this->ids['Contact'][$name];
      $this->assertEquals($expected, $rows[$contactID]['donor_segment_overall'], "Wrong donor_segment_overall for '$name'.");
      $this->assertEquals($expectedLabel, $rows[$contactID]['donor_segment_overall:label'], "Wrong label for '$name'.");
      $this->assertStringContainsString($expectedDescription, $rows[$contactID]['donor_segment_overall:description'], "Wrong description for '$name'.");
    }

    $this->clearWMFDonorData();
    WMFDonor::update(FALSE)
      ->addWhere('id', 'IN', array_values($this->ids['Contact']))
      ->setValues(['donor_segment_overall' => TRUE])
      ->execute();
    $stored = Contact::get(FALSE)
      ->addWhere('id', 'IN', array_values($this->ids['Contact']))
      ->addSelect('id', 'wmf_donor.donor_segment_overall')
      ->execute()->indexBy('id');
    foreach ($segments as $name => [, $expected]) {
      $this->assertEquals($expected, $stored[$this->ids['Contact'][$name]]['wmf_donor.donor_segment_overall'], "Wrong stored donor_segment_overall for '$name'.");
    }
  }

  /**
   * Every branch of the overall donor status calculation.
   *
   * Each scenario gives in the listed financial years (offset in years from the
   * frozen current date) and expects a status value and :name.
   *
   * @throws \CRM_Core_Exception
   */
  public function testWMFDonorStatusOverallBranches(): void {
    // [expected status, expected status name, [years given]
    $scenarios = [
      'consecutive' => [10, 'consecutive', [0, 1]],
      'reactivated' => [20, 'reactivated', [0, 2]],
      'new' => [30, 'new', [0]],
      'consecutive_last_year' => [40, 'lybunt', [1, 2]],
      'reactivated_last_year' => [50, 'reactivated_last_year', [1, 3]],
      'new_last_year' => [60, 'new_last_year', [1]],
      'lapsed' => [70, 'lapsed', [2]],
      'deep_lapsed' => [80, 'deep_lapsed', [4]],
      'ultra_lapsed' => [90, 'ultra_lapsed', [7]],
    ];
    foreach ($scenarios as $name => [, , $years]) {
      $this->createIndividual([], $name);
      foreach ($years as $offset) {
        $this->createTestEntity('Contribution', [
          'contact_id' => $this->ids['Contact'][$name],
          'receive_date' => $this->financialYearDate($offset),
          'total_amount' => 10,
          'financial_type_id:name' => 'Donation',
        ], $name . '_' . $offset);
      }
    }

    $rows = WMFDonor::get(FALSE)
      ->addSelect('donor_status_overall', 'donor_status_overall:name')
      ->addWhere('id', 'IN', array_values($this->ids['Contact']))
      ->execute()->indexBy('id');

    foreach ($scenarios as $name => [$expected, $expectedName]) {
      $contactID = $this->ids['Contact'][$name];
      $this->assertEquals($expected, $rows[$contactID]['donor_status_overall'], "Wrong donor_status_overall for '$name'.");
      $this->assertEquals($expectedName, $rows[$contactID]['donor_status_overall:name'], "Wrong donor_status_overall:name for '$name'.");
    }
  }

  /**
   * donor_status_otg only counts one-time gifts, donor_status_overall counts all.
   *
   * Both donors give a mix of recurring and one-time gifts across the same three
   * financial years, so each is a consecutive overall donor. Their one-time-gift
   * status differs by which of those gifts were recurring: when the recent gifts
   * are recurring the donor is otg lapsed, when the recent gifts are one-time the
   * donor is otg consecutive.
   *
   * @throws \CRM_Core_Exception
   */
  public function testWMFDonorStatusOTG(): void {
    // [expected overall, expected otg, [[year offset, is recurring], ...]]
    $scenarios = [
      'recurring_recent' => [10, 70, [[0, TRUE], [1, TRUE], [2, FALSE]]],
      'onetime_recent' => [10, 10, [[0, FALSE], [1, FALSE], [2, TRUE]]],
    ];
    foreach ($scenarios as $name => [, , $gifts]) {
      $this->createIndividual([], $name);
      $this->createTestEntity('ContributionRecur', [
        'contact_id' => $this->ids['Contact'][$name],
        'frequency_unit' => 'month',
        'frequency_interval' => 1,
        'amount' => 10,
        'start_date' => $this->financialYearDate(2),
      ], $name);
      foreach ($gifts as $index => [$offset, $isRecurring]) {
        $this->createTestEntity('Contribution', [
          'contact_id' => $this->ids['Contact'][$name],
          'contribution_recur_id' => $isRecurring ? $this->ids['ContributionRecur'][$name] : NULL,
          'receive_date' => $this->financialYearDate($offset),
          'total_amount' => 10,
          'financial_type_id:name' => 'Donation',
        ], $name . '_' . $index);
      }
    }

    $rows = WMFDonor::get(FALSE)
      ->addSelect('donor_status_overall', 'donor_status_otg')
      ->addWhere('id', 'IN', array_values($this->ids['Contact']))
      ->execute()->indexBy('id');
    foreach ($scenarios as $name => [$overall, $otg]) {
      $contactID = $this->ids['Contact'][$name];
      $this->assertEquals($overall, $rows[$contactID]['donor_status_overall'], "Wrong donor_status_overall for '$name'.");
      $this->assertEquals($otg, $rows[$contactID]['donor_status_otg'], "Wrong donor_status_otg for '$name'.");
    }

    // Trigger-written values match the ::get calculation.
    $stored = Contact::get(FALSE)
      ->addWhere('id', 'IN', array_values($this->ids['Contact']))
      ->addSelect('id', 'wmf_donor.donor_status_overall', 'wmf_donor.donor_status_otg')
      ->execute()->indexBy('id');
    foreach ($scenarios as $name => [$overall, $otg]) {
      $contactID = $this->ids['Contact'][$name];
      $this->assertEquals($overall, $stored[$contactID]['wmf_donor.donor_status_overall'], "Wrong stored donor_status_overall for '$name'.");
      $this->assertEquals($otg, $stored[$contactID]['wmf_donor.donor_status_otg'], "Wrong stored donor_status_otg for '$name'.");
    }

    // Backfill path writes the same values to wmf_donor.
    $this->clearWMFDonorData();
    WMFDonor::update(FALSE)
      ->addWhere('id', 'IN', array_values($this->ids['Contact']))
      ->setValues(['donor_status_overall' => TRUE, 'donor_status_otg' => TRUE])
      ->execute();
    $updated = Contact::get(FALSE)
      ->addWhere('id', 'IN', array_values($this->ids['Contact']))
      ->addSelect('id', 'wmf_donor.donor_status_overall', 'wmf_donor.donor_status_otg')
      ->execute()->indexBy('id');
    foreach ($scenarios as $name => [$overall, $otg]) {
      $contactID = $this->ids['Contact'][$name];
      $this->assertEquals($overall, $updated[$contactID]['wmf_donor.donor_status_overall'], "Wrong backfilled donor_status_overall for '$name'.");
      $this->assertEquals($otg, $updated[$contactID]['wmf_donor.donor_status_otg'], "Wrong backfilled donor_status_otg for '$name'.");
    }
  }

  /**
   * last_otg_donation_date only gives non-recurring donations.
   *
   * One contact with an otg, a monthly recurring and an annual recurring
   * contribution on distinct dates, read in the trigger context.
   *
   * @throws \CRM_Core_Exception
   */
  public function testWMFDonorLastDonationDates(): void {
    $this->createIndividual([], 'donor');
    $this->createTestEntity('Contribution', [
      'contact_id' => $this->ids['Contact']['donor'],
      'receive_date' => '2023-03-01',
      'total_amount' => 10,
      'financial_type_id:name' => 'Donation',
    ], 'otg');
    $this->createTestEntity('ContributionRecur', [
      'contact_id' => $this->ids['Contact']['donor'],
      'frequency_unit' => 'month',
      'frequency_interval' => 1,
      'amount' => 10,
      'start_date' => '2023-09-01',
    ], 'month');
    $this->createTestEntity('Contribution', [
      'contact_id' => $this->ids['Contact']['donor'],
      'contribution_recur_id' => $this->ids['ContributionRecur']['month'],
      'receive_date' => '2023-09-01',
      'total_amount' => 10,
      'financial_type_id:name' => 'Donation',
    ], 'month');

    $donor = Contact::get(FALSE)
      ->addWhere('id', '=', $this->ids['Contact']['donor'])
      ->addSelect('wmf_donor.last_otg_donation_date')
      ->execute()->first();

    $this->assertEquals('2023-03-01 00:00:00', $donor['wmf_donor.last_otg_donation_date']);
  }

  /**
   * first_donation_was_recur reflects the earliest donation.
   *
   * Covers the three options: contribution directly linked by
   * contribution_recur_id, post payment monthly convert (sharing
   * an invoice_id with a recur), or not recurring at all, plus a
   * donor with no donations, which stays NULL.
   *
   * @throws \CRM_Core_Exception
   */
  public function testWMFDonorFirstDonationWasRecur(): void {
    $early = '2020-06-01';
    $late = '2021-06-01';

    $this->createIndividual([], 'first_recur');
    $this->createTestEntity('ContributionRecur', [
      'contact_id' => $this->ids['Contact']['first_recur'],
      'frequency_unit' => 'month',
      'frequency_interval' => 1,
      'amount' => 10,
      'start_date' => $early,
    ], 'first_recur');
    $this->createTestEntity('Contribution', [
      'contact_id' => $this->ids['Contact']['first_recur'],
      'contribution_recur_id' => $this->ids['ContributionRecur']['first_recur'],
      'receive_date' => $early,
      'total_amount' => 10,
      'financial_type_id:name' => 'Donation',
    ], 'first_recur');

    $this->createIndividual([], 'ppmc');
    $this->createTestEntity('ContributionRecur', [
      'contact_id' => $this->ids['Contact']['ppmc'],
      'frequency_unit' => 'month',
      'frequency_interval' => 1,
      'amount' => 10,
      'start_date' => $early,
      'invoice_id' => 'shared-invoice-1',
    ], 'ppmc');
    $this->createTestEntity('Contribution', [
      'contact_id' => $this->ids['Contact']['ppmc'],
      'invoice_id' => 'shared-invoice-1',
      'receive_date' => $early,
      'total_amount' => 10,
      'financial_type_id:name' => 'Donation',
    ], 'ppmc');

    $this->createIndividual([], 'first_onetime');
    $this->createTestEntity('Contribution', [
      'contact_id' => $this->ids['Contact']['first_onetime'],
      'receive_date' => $early,
      'total_amount' => 10,
      'financial_type_id:name' => 'Donation',
    ], 'first_onetime_early');
    $this->createTestEntity('ContributionRecur', [
      'contact_id' => $this->ids['Contact']['first_onetime'],
      'frequency_unit' => 'month',
      'frequency_interval' => 1,
      'amount' => 10,
      'start_date' => $late,
    ], 'first_onetime');
    $this->createTestEntity('Contribution', [
      'contact_id' => $this->ids['Contact']['first_onetime'],
      'contribution_recur_id' => $this->ids['ContributionRecur']['first_onetime'],
      'receive_date' => $late,
      'total_amount' => 10,
      'financial_type_id:name' => 'Donation',
    ], 'first_onetime_late');

    $this->createIndividual([], 'no_donations');

    $rows = Contact::get(FALSE)
      ->addWhere('id', 'IN', array_values($this->ids['Contact']))
      ->addSelect('id', 'wmf_donor.first_donation_was_recur')
      ->execute()->indexBy('id');

    $this->assertTrue($rows[$this->ids['Contact']['first_recur']]['wmf_donor.first_donation_was_recur'], 'Recur-linked first donation should be recurring.');
    $this->assertTrue($rows[$this->ids['Contact']['ppmc']]['wmf_donor.first_donation_was_recur'], 'PPMC first donation should be recurring.');
    $this->assertFalse($rows[$this->ids['Contact']['first_onetime']]['wmf_donor.first_donation_was_recur'], 'A one-time first donation should not be recurring.');
    $this->assertNull($rows[$this->ids['Contact']['no_donations']]['wmf_donor.first_donation_was_recur'], 'A donor with no donations should be NULL.');
  }

  /**
   * Test every branch of the recurring donor status calculation.
   *
   * @throws \CRM_Core_Exception
   */
  public function testWMFDonorRecurStatusBranches(): void {
    $beforeFinancialYear = date('Y-m-d', strtotime('-2 years', strtotime($this->currentDate)));
    $thisFinancialYear = $this->currentDate;
    $soon = date('Y-m-d', strtotime('+1 week', strtotime($this->currentDate)));
    $paused = date('Y-m-d', strtotime('+6 months', strtotime($this->currentDate)));

    $scenarios = [
      // Active status, started before this financial year.
      'active' => [15, [['In Progress', $beforeFinancialYear, $soon]]],
      // Active status, but only started this financial year.
      'new' => [25, [['In Progress', $thisFinancialYear, $soon]]],
      // Every active recurring is scheduled beyond a month out.
      'all_paused' => [35, [['In Progress', $beforeFinancialYear, $paused], ['In Progress', $beforeFinancialYear, $paused]]],
      // One paused among active ones stays Active, not Paused.
      'one_of_many_paused' => [15, [['In Progress', $beforeFinancialYear, $paused], ['In Progress', $beforeFinancialYear, $soon]]],
      'failing' => [45, [['Failing', $beforeFinancialYear, NULL]]],
      'failed' => [55, [['Failed', $beforeFinancialYear, NULL]]],
      'cancelled' => [65, [['Cancelled', $beforeFinancialYear, NULL]]],
    ];

    foreach ($scenarios as $name => [$expected, $recurs]) {
      $this->createIndividual([], $name);
      foreach ($recurs as $index => [$status, $startDate, $nextScheduled]) {
        $this->createTestEntity('ContributionRecur', [
          'contact_id' => $this->ids['Contact'][$name],
          'frequency_unit' => 'month',
          'frequency_interval' => 1,
          'amount' => 10,
          'contribution_status_id:name' => $status,
          'start_date' => $startDate,
          'next_sched_contribution_date' => $nextScheduled,
        ], $name . '_' . $index);
      }
    }

    $rows = (array) WMFDonor::get(FALSE)
      ->addSelect('donor_status_recur_month')
      ->addWhere('id', 'IN', array_values($this->ids['Contact']))
      ->execute()->indexBy('id');

    foreach ($scenarios as $name => [$expected]) {
      $contactID = $this->ids['Contact'][$name];
      $this->assertArrayHasKey($contactID, $rows, "No row returned for the '$name' scenario.");
      $this->assertEquals($expected, $rows[$contactID]['donor_status_recur_month'], "Wrong donor_status_recur_month for the '$name' scenario.");
    }
  }

  /**
   * Test donor_status_recur_overall across mixed recurring frequencies.
   *
   * The overall field considers recurrings of every frequency, with each
   * judged as paused against its own frequency's window.
   *
   * @throws \CRM_Core_Exception
   */
  public function testWMFDonorRecurStatusOverall(): void {
    $beforeFinancialYear = date('Y-m-d', strtotime('-2 years', strtotime($this->currentDate)));
    $soon = date('Y-m-d', strtotime('+1 week', strtotime($this->currentDate)));
    $sixMonthsOut = date('Y-m-d', strtotime('+6 months', strtotime($this->currentDate)));
    $eighteenMonthsOut = date('Y-m-d', strtotime('+18 months', strtotime($this->currentDate)));

    // [expected month, year & overall statuses, recurs [frequency, status, start date, next scheduled]]
    $scenarios = [
      // A cancelled annual doesn't drag down an active monthly.
      'cancelled_annual_active_monthly' => [15, 65, 15, [
        ['month', 'In Progress', $beforeFinancialYear, $soon],
        ['year', 'Cancelled', $beforeFinancialYear, NULL],
      ]],
      // Six months out pauses a monthly but is on-schedule for an annual.
      'paused_monthly_active_annual' => [35, 15, 15, [
        ['month', 'In Progress', $beforeFinancialYear, $sixMonthsOut],
        ['year', 'In Progress', $beforeFinancialYear, $sixMonthsOut],
      ]],
      // Paused overall requires every active recurring to be beyond its own window.
      'all_paused' => [35, 35, 35, [
        ['month', 'In Progress', $beforeFinancialYear, $sixMonthsOut],
        ['year', 'In Progress', $beforeFinancialYear, $eighteenMonthsOut],
      ]],
      // A monthly started this year is New for month, but the old annual means
      // the donor was already a recurring donor overall, so Active.
      'new_monthly_old_cancelled_annual' => [25, 65, 15, [
        ['month', 'In Progress', $this->currentDate, $soon],
        ['year', 'Cancelled', $beforeFinancialYear, NULL],
      ]],
      // Only an annual: the month field falls through to Never.
      'failing_annual_only' => [95, 45, 45, [
        ['year', 'Failing', $beforeFinancialYear, NULL],
      ]],
    ];

    foreach ($scenarios as $name => [, , , $recurs]) {
      $this->createIndividual([], $name);
      foreach ($recurs as $index => [$frequency, $status, $startDate, $nextScheduled]) {
        $this->createTestEntity('ContributionRecur', [
          'contact_id' => $this->ids['Contact'][$name],
          'frequency_unit' => $frequency,
          'frequency_interval' => 1,
          'amount' => 10,
          'contribution_status_id:name' => $status,
          'start_date' => $startDate,
          'next_sched_contribution_date' => $nextScheduled,
        ], $name . '_' . $index);
      }
    }

    $rows = WMFDonor::get(FALSE)
      ->addSelect('donor_status_recur_month', 'donor_status_recur_year', 'donor_status_recur_overall')
      ->addWhere('id', 'IN', array_values($this->ids['Contact']))
      ->execute()->indexBy('id');

    foreach ($scenarios as $name => [$expectedMonth, $expectedYear, $expectedOverall]) {
      $contactID = $this->ids['Contact'][$name];
      $this->assertEquals($expectedMonth, $rows[$contactID]['donor_status_recur_month'], "Wrong donor_status_recur_month for the '$name' scenario.");
      $this->assertEquals($expectedYear, $rows[$contactID]['donor_status_recur_year'], "Wrong donor_status_recur_year for the '$name' scenario.");
      $this->assertEquals($expectedOverall, $rows[$contactID]['donor_status_recur_overall'], "Wrong donor_status_recur_overall for the '$name' scenario.");
    }
  }

  /**
   * unPauseRecurring recalculates recur statuses for donors who shouldn't be paused any more.
   *
   * @throws \CRM_Core_Exception
   */
  public function testWMFDonorUnPauseRecurring(): void {
    $beforeFinancialYear = date('Y-m-d', strtotime('-2 years', strtotime($this->currentDate)));
    $withinWindow = date('Y-m-d', strtotime('+1 week', strtotime($this->currentDate)));
    $yearOut = date('Y-m-d', strtotime('+18 months', strtotime($this->currentDate)));

    // An active monthly recurring due within the window, so no longer
    // paused, but carrying a stored paused status left over from before.
    $this->createIndividual([], 'active');
    $this->createTestEntity('ContributionRecur', [
      'contact_id' => $this->ids['Contact']['active'],
      'frequency_unit' => 'month',
      'frequency_interval' => 1,
      'amount' => 10,
      'contribution_status_id:name' => 'In Progress',
      'start_date' => $beforeFinancialYear,
      'next_sched_contribution_date' => $withinWindow,
    ], 'active');
    Contact::update(FALSE)
      ->addWhere('id', '=', $this->ids['Contact']['active'])
      ->addValue('wmf_donor.donor_status_recur_month', 35)
      ->addValue('wmf_donor.donor_status_recur_year', 95)
      ->addValue('wmf_donor.donor_status_recur_overall', 35)
      ->execute();

    // Genuinely paused: both active recurrings are scheduled beyond their own
    // window, so the donor should be excluded and left as-is.
    $this->createIndividual([], 'paused');
    $this->createTestEntity('ContributionRecur', [
      'contact_id' => $this->ids['Contact']['paused'],
      'frequency_unit' => 'month',
      'frequency_interval' => 1,
      'amount' => 10,
      'contribution_status_id:name' => 'In Progress',
      'start_date' => $beforeFinancialYear,
      'next_sched_contribution_date' => $yearOut,
    ], 'paused_month');
    $this->createTestEntity('ContributionRecur', [
      'contact_id' => $this->ids['Contact']['paused'],
      'frequency_unit' => 'year',
      'frequency_interval' => 1,
      'amount' => 10,
      'contribution_status_id:name' => 'In Progress',
      'start_date' => $beforeFinancialYear,
      'next_sched_contribution_date' => $yearOut,
    ], 'paused_year');
    // A wrong sentinel a recompute would overwrite if the donor were touched.
    Contact::update(FALSE)
      ->addWhere('id', '=', $this->ids['Contact']['paused'])
      ->addValue('wmf_donor.donor_status_recur_month', 65)
      ->addValue('wmf_donor.donor_status_recur_year', 35)
      ->addValue('wmf_donor.donor_status_recur_overall', 35)
      ->execute();

    // Month should be unpaused, year should stay paused.
    $this->createIndividual([], 'mixed');
    $this->createTestEntity('ContributionRecur', [
      'contact_id' => $this->ids['Contact']['mixed'],
      'frequency_unit' => 'month',
      'frequency_interval' => 1,
      'amount' => 10,
      'contribution_status_id:name' => 'Pending',
      'start_date' => $beforeFinancialYear,
      'next_sched_contribution_date' => $withinWindow,
    ], 'mixed_month');
    $this->createTestEntity('ContributionRecur', [
      'contact_id' => $this->ids['Contact']['mixed'],
      'frequency_unit' => 'year',
      'frequency_interval' => 1,
      'amount' => 10,
      'contribution_status_id:name' => 'In Progress',
      'start_date' => $beforeFinancialYear,
      'next_sched_contribution_date' => $yearOut,
    ], 'mixed_year');
    Contact::update(FALSE)
      ->addWhere('id', '=', $this->ids['Contact']['mixed'])
      ->addValue('wmf_donor.donor_status_recur_month', 35)
      ->addValue('wmf_donor.donor_status_recur_overall', 35)
      ->execute();

    WMFDonor::unPauseRecurring(FALSE)->execute();

    // The active donor is recomputed back to active.
    $active = $this->getStoredRecurStatus('active');
    $this->assertEquals(15, $active['donor_status_recur_month']);
    $this->assertEquals(95, $active['donor_status_recur_year']);
    $this->assertEquals(15, $active['donor_status_recur_overall']);

    // The genuinely paused donor is excluded, so its sentinel is left untouched.
    $this->assertEquals(65, $this->getStoredRecurStatus('paused')['donor_status_recur_month']);

    // Month recalculated, year unchanged.
    $mixed = $this->getStoredRecurStatus('mixed');
    $this->assertEquals(15, $mixed['donor_status_recur_month']);
    $this->assertEquals(35, $mixed['donor_status_recur_year']);
    $this->assertEquals(15, $mixed['donor_status_recur_overall']);
  }

  /**
   * Get the stored wmf_donor recur statuses for a test contact.
   *
   * @param string $identifier
   *
   * @return array
   * @throws \CRM_Core_Exception
   */
  private function getStoredRecurStatus(string $identifier): array {
    $row = Contact::get(FALSE)
      ->addWhere('id', '=', $this->ids['Contact'][$identifier])
      ->addSelect('wmf_donor.donor_status_recur_month', 'wmf_donor.donor_status_recur_year', 'wmf_donor.donor_status_recur_overall')
      ->execute()->first();
    return [
      'donor_status_recur_month' => $row['wmf_donor.donor_status_recur_month'],
      'donor_status_recur_year' => $row['wmf_donor.donor_status_recur_year'],
      'donor_status_recur_overall' => $row['wmf_donor.donor_status_recur_overall'],
    ];
  }

  /**
   * @dataProvider segmentDataProvider
   *
   * @param $status
   * @param $segment
   * @param $contributions
   *
   * @throws \CRM_Core_Exception
   * @throws \Civi\API\Exception\UnauthorizedException
   */
  public function testDonorSegmentTriggers($status, $segment, $contributions): void {
    foreach ($contributions as $contribution) {
      $this->createDonor($contribution);
    }
    $contact = Contact::get(FALSE)
      ->addWhere('id', '=', $this->ids['Contact']['donor'])
      ->addSelect('wmf_donor.donor_segment_id:name', 'wmf_donor.donor_status_id:name')->execute()->first();
    $this->assertEquals($status, $contact['wmf_donor.donor_status_id:name']);
    $this->assertEquals($segment, $contact['wmf_donor.donor_segment_id:name']);

    // The above loaded the trigger-generated value but let's test the on-the-fly too.
    $result = WMFDonor::get(FALSE)
      ->setDebug(TRUE)
      ->addSelect('donor_segment_id:name', 'donor_status_id:name')
      ->addWhere('id', 'IN', $this->ids['Contact'])
      ->execute()->first();

    $this->assertEquals($status, $result['donor_status_id:name']);
    $this->assertEquals($segment, $result['donor_segment_id:name']);
  }

  /**
   * Get segments to test.
   *
   * Note that all we freeze the date to 01 Aug of the current year
   * before testing. So all dates are 'as if today were' 01 Aug xx
   * and php gets that date for all it's date functions.
   *
   * @return array[]
   */
  public function segmentDataProvider(): array {
    return [
      'new_major_donor' => [
        'status' => 'new',
        'segment' => 'major_donor',
        'contributions' => [['receive_date' => 'today', 'total_amount' => 12000]],
      ],
      'consecutive_major_donor' => [
        'status' => 'consecutive',
        'segment' => 'major_donor',
        'contributions' => [['receive_date' => 'today', 'total_amount' => 10], ['receive_date' => '1 year ago', 'total_amount' => 12000]],
      ],
      'ultra_lapsed' => [
        'status' => 'ultra_lapsed',
        'segment' => 'other_donor',
        'contributions' => [['receive_date' => '2016-12-10', 'total_amount' => 50]],
      ],
    ];
  }

  /**
   * Create a donor contact.
   *
   * @throws \CRM_Core_Exception
   */
  public function createDonor($contributionParams = [], $identifier = 'donor'): void {
    if (empty($this->ids['Contact'][$identifier])) {
      $this->createIndividual([], $identifier);
    }
    if (!empty($contributionParams['receive_date']) && !str_starts_with($contributionParams['receive_date'], 2)) {
      $contributionParams['receive_date'] = date('Y-m-d', strtotime($contributionParams['receive_date'], strtotime($this->currentDate)));
    }
    Contribution::create(FALSE)->setValues(array_merge([
      'receive_date' => $this->getDate(),
      'financial_type_id:name' => 'Donation',
      'total_amount' => 20000,
      'contact_id' => $this->ids['Contact'][$identifier],
    ], $contributionParams))->execute();
  }

  /**
   * Get the data of our contribution.
   *
   * @return false|string
   */
  public function getDate() {
    return date('Y-m-d', strtotime('- 2 years', strtotime($this->currentDate)));
  }

  /**
   * Get a date the given number of years before the frozen current date.
   *
   * @param int $yearsAgo
   *
   * @return string
   */
  protected function financialYearDate(int $yearsAgo = 0): string {
    return date('Y-m-d', strtotime("-$yearsAgo years", strtotime($this->currentDate)));
  }

  /**
   * Test updating WMF donor fields for a contact.
   *
   * @throws \CRM_Core_Exception
   */
  public function testWMFDonorUpdate(): void {
    $this->createDonor();
    $this->clearWMFDonorData();
    WMFDonor::update(FALSE)
      ->addWhere('id', '=', $this->ids['Contact']['donor'])
      // Normally setValues() would specify field => value but since it is
      // calculated this is something of a placeholder. However, I think we could
      // specify fieldName => TRUE to limit or * => TRUE for all.
      ->setValues(['*' => TRUE])
      ->execute();
    $updated = Contact::get(FALSE)
      ->addWhere('id', '=', $this->ids['Contact']['donor'])
      ->addSelect('wmf_donor.donor_segment_id', 'custom.*')
      ->execute()->first();
    $this->assertEquals(100, $updated['wmf_donor.donor_segment_id']);
  }

  /**
   * Test updating WMF donor fields for more than one contact.
   *
   * @throws \CRM_Core_Exception
   */
  public function testWMFDonorUpdateMultiple(): void {
    $this->createDonor();
    $this->createDonor([], 'second');
    $this->clearWMFDonorData();

    $updatedContacts = $this->updateWMFDonorData();
    foreach ($updatedContacts as $updatedContact) {
      $this->assertEquals(100, $updatedContact['wmf_donor.donor_segment_id']);
      $this->assertEquals(20000, $updatedContact['wmf_donor.lifetime_including_endowment']);
    }

    $this->clearWMFDonorData();
    // Now do it again specifying only the donor_segment_id field - the other should not update.
    $updatedContacts = $this->updateWMFDonorData(['donor_segment_id' => TRUE]);
    foreach ($updatedContacts as $updatedContact) {
      $this->assertEquals(100, $updatedContact['wmf_donor.donor_segment_id']);
      $this->assertEquals(0, $updatedContact['wmf_donor.lifetime_including_endowment']);
    }
  }

  /**
   * Test updateAnnualDonors API action.
   *
   * @throws \CRM_Core_Exception
   */
  public function testUpdateAnnualDonors(): void {
    $this->createDonor(['total_amount' => 2], 'current');
    $this->createDonor(['total_amount' => 2], 'delinquent');
    $this->createDonor(['total_amount' => 2], 'lapsed');
    $this->createDonor(['total_amount' => 2], 'onetime');
    $this->clearWMFDonorData();

    $annualContributionRecurs = [
      'current' => ['cancel_date' => NULL, 'status' => 2, 'donor_segment_id' => 450, 'donor_status_id' => 12],
      'delinquent' => ['cancel_date' => '2 months ago', 'status' => 1, 'donor_segment_id' => 450, 'donor_status_id' => 14],
      'lapsed' => ['cancel_date' => '9 months ago', 'status' => 3, 'donor_segment_id' => 450, 'donor_status_id' => 16],
      'onetime' => ['cancel_date' => '15 months ago', 'status' => 4, 'donor_segment_id' => 600, 'donor_status_id' => 30],
    ];

    foreach ($annualContributionRecurs as $name => $recur) {
      $this->createTestEntity('ContributionRecur', [
        'contact_id' => $this->ids['Contact'][$name],
        'frequency_unit' => 'year',
        'frequency_interval' => 1,
        'amount' => 11,
      ], $name);
      $this->createTestEntity('Contribution', [
        'contact_id' => $this->ids['Contact'][$name],
        'total_amount' => 11,
        'financial_type_id:name' => 'Donation',
        'contribution_recur_id' => $this->ids['ContributionRecur'][$name],
      ], $name);
    }

    $updatedContacts = $this->updateWMFDonorData();
    foreach ($updatedContacts as $updatedContact) {
      $this->assertEquals(450, $updatedContact['wmf_donor.donor_segment_id']);
      $this->assertEquals(12, $updatedContact['wmf_donor.donor_status_id']);
    }

    foreach ($annualContributionRecurs as $name => $recur) {
      $this->updateRecur([
        'contribution_status_id' => $recur['status'],
        'cancel_date' => $recur['cancel_date'] ? date('Y-m-d', strtotime($recur['cancel_date'], strtotime($this->currentDate))) : NULL,
      ], $name);
    }

    WMFDonor::updateAnnualDonors(FALSE)->execute();

    foreach ($annualContributionRecurs as $name => $recur) {
      $donor = Contact::get(FALSE)
        ->addWhere('id', '=', $this->ids['Contact'][$name])
        ->addSelect('wmf_donor.donor_segment_id', 'wmf_donor.donor_status_id')
        ->execute()->first();
      $this->assertEquals($recur['donor_segment_id'], $donor['wmf_donor.donor_segment_id']);
      $this->assertEquals($recur['donor_status_id'], $donor['wmf_donor.donor_status_id']);
    }
  }

  /**
   * @param bool[] $fields
   *
   * @return \Civi\Api4\Generic\Result
   * @throws \CRM_Core_Exception
   */
  protected function updateWMFDonorData(array $fields = ['*' => TRUE]): Result {
    WMFDonor::update(FALSE)
      ->addWhere('id', 'IN', $this->ids['Contact'])
      // Normally setValues() would specify field => value but since it is
      // calculated this is something of a placeholder. However, I think we could
      // specify fieldName => TRUE to limit or * => TRUE for all.
      ->setValues($fields)
      ->execute();
    return Contact::get(FALSE)
      ->addWhere('id', 'IN', $this->ids['Contact'])
      ->addSelect('wmf_donor.donor_segment_id', 'wmf_donor.donor_status_id', 'wmf_donor.lifetime_including_endowment')
      ->execute();
  }

  /**
   * @throws \Civi\Core\Exception\DBQueryException
   */
  protected function clearWMFDonorData(): void {
    \CRM_Core_DAO::executeQuery('DELETE FROM wmf_donor WHERE entity_id IN (%1)', [
      1 => [implode(',', $this->ids['Contact']), 'CommaSeparatedIntegers'],
    ]);
  }

}

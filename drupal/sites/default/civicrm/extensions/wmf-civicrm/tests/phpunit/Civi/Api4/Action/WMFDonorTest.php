<?php

namespace Civi\Api4\Action;

use Civi\Api4\Contact;
use Civi\Api4\Contribution;
use Civi\Api4\Generic\Result;
use Civi\Api4\WMFDonor;
use Civi\Test;
use Civi\Test\HeadlessInterface;
use Civi\Test\HookInterface;
use PHPUnit\Framework\TestCase;

/**
 * Test our thank you cleanup.
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

  /**
   * Current date.
   *
   * We force this to get consistent test results.
   *
   * @var string
   */
  protected $currentDate;

  /**
   * @return \Civi\Test\CiviEnvBuilder
   * @throws \CRM_Extension_Exception_ParseException
   */
  public function setUpHeadless(): Test\CiviEnvBuilder {
    // Civi\Test has many helpers, like install(), uninstall(), sql(), and sqlFile().
    // See: https://docs.civicrm.org/dev/en/latest/testing/phpunit/#civitest
    return Test::headless()
      ->installMe(__DIR__)
      ->apply();
  }

  public function setUp(): void {
    $this->currentDate = date('Y') . '-08-01';
    \CRM_Utils_Time::setTime($this->currentDate);
    parent::setUp();
  }
  /**
   * @throws \CRM_Core_Exception
   */
  public function tearDown(): void {
    if (!empty($this->ids['Contact'])) {
      Contribution::delete(FALSE)
        ->addWhere('contact_id', 'IN', $this->ids['Contact'])
        ->execute();
      Contact::delete(FALSE)->addWhere('id', 'IN', $this->ids['Contact'])->execute();
    }
    \CRM_Utils_Time::resetTime();
    parent::tearDown();
  }

  /**
   * Test getFields works for WMFDonor pseudo entity.
   *
   * @throws \CRM_Core_Exception
   */
  public function testWMFDonorGetFields(): void {
    $fields = WMFDonor::getFields(FALSE)->execute()->indexBy('name');
    $this->assertNotEmpty($fields['last_donation_date']);
  }

  /**
   * Test that we can get WMF Donor calculated fields.
   *
   * @throws \CRM_Core_Exception
   */
  public function testWMFDonorGet(): void {
    $this->createDonor();
    // Select last_donation_date only.
    $result = WMFDonor::get(FALSE)
      ->addSelect('last_donation_date')
      ->addWhere('id', '=', $this->ids['Contact']['donor'])
      ->execute()->first();
    $this->assertEquals($this->getDate() . ' 00:00:00', $result['last_donation_date']);

    // Do not specify fields.
    $result = WMFDonor::get(FALSE)
      ->addWhere('id', '=', $this->ids['Contact']['donor'])
      ->execute()->first();
    $this->assertEquals($this->getDate() .  ' 00:00:00', $result['last_donation_date']);

    // Specify a field that requires an additional join.
    $result = WMFDonor::get(FALSE)
      ->addSelect('last_donation_usd')
      ->addWhere('id', '=', $this->ids['Contact']['donor'])
      ->execute()->first();
    $this->assertEquals(20000, $result['last_donation_usd']);
  }

  /**
   * Test the insanity that is donor segmentation..
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
  public function segmentDataProvider() : array {
    return [
      'new_major_donor' => [
        'status' => 'new',
        'segment' => 'major_donor',
        'contributions' => [['receive_date' => 'yesterday', 'total_amount' => 12000]],
      ],
      'consecutive_major_donor' => [
        'status' => 'consecutive',
        'segment' => 'major_donor',
        'contributions' => [['receive_date' => 'yesterday', 'total_amount' => 10], ['receive_date' => '8 months ago', 'total_amount' => 12000]],
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
      $this->createContact($identifier);
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
   * @param $identifier
   *
   * @throws \CRM_Core_Exception
   * @throws \Civi\API\Exception\UnauthorizedException
   */
  protected function createContact($identifier): void {
    $this->ids['Contact'][$identifier] = Contact::create(FALSE)
      ->setValues([
        'first_name' => 'Billy',
        'last_name' => 'Bill',
        'contact_type' => 'Individual'
      ])
      ->execute()
      ->first()['id'];
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
      $this->assertEquals(20000, $updatedContact['wmf_donor.lifetime_usd_total']);
    }

    $this->clearWMFDonorData();
    // Now do it again specifying only the donor_segment_id field - the other should not update.
    $updatedContacts = $this->updateWMFDonorData(['donor_segment_id' => TRUE]);
    foreach ($updatedContacts as $updatedContact) {
      $this->assertEquals(100, $updatedContact['wmf_donor.donor_segment_id']);
      $this->assertEquals(0, $updatedContact['wmf_donor.lifetime_usd_total']);
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
      ->addSelect('wmf_donor.donor_segment_id', 'wmf_donor.lifetime_usd_total')
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
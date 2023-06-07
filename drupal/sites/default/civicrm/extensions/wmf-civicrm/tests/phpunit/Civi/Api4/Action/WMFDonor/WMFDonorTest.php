<?php

namespace phpunit\Civi\Api4\Action;

use Civi\Api4\Contact;
use Civi\Api4\Contribution;
use Civi\Api4\WMFDonor;
use Civi\Test;
use Civi\Test\HeadlessInterface;
use Civi\Test\HookInterface;
use Civi\Test\TransactionalInterface;
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
class WMFDonorTest extends TestCase implements HeadlessInterface, HookInterface, TransactionalInterface {

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
   * Create a donor contact.
   *
   * @throws \CRM_Core_Exception
   */
  public function createDonor($contributionParams = [], $identifier = 'donor'): void {
    $this->createContact($identifier);
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
    return date('Y-m-02', strtotime('-13 months'));
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
}

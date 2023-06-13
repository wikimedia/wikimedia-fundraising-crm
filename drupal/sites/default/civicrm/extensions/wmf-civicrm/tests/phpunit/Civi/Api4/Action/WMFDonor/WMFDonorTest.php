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
    $result = WMFDonor::get(FALSE)->addSelect('last_donation_date')
      ->addWhere('id', '=', $this->ids['Contact']['donor'])
      ->execute()->first();
    $this->assertEquals('2021-08-02 00:00:00', $result['last_donation_date']);
  }

  /**
   * Create a donor contact.
   *
   * @throws \CRM_Core_Exception
   */
  public function createDonor(): void {
    $this->ids['Contact']['donor'] = Contact::create(FALSE)->setValues(['first_name' => 'Billy', 'last_name' => 'Bill', 'contact_type' => 'Individual'])->execute()->first()['id'];
    Contribution::create(FALSE)->setValues([
      'receive_date' => '2021-08-02',
      'financial_type_id:name' => 'Donation',
      'total_amount' => 1,
      'contact_id' => $this->ids['Contact']['donor'],
    ])->execute();
  }
}

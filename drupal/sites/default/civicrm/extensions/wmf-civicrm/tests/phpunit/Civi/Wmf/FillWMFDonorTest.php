<?php

namespace phpunit\Civi\Wmf;

use Civi\Api4\Contact;
use Civi\Api4\Contribution;
use Civi\Api4\WMFDataManagement;
use Civi\Test;
use Civi\Test\Api3TestTrait;
use Civi\Test\HeadlessInterface;
use Civi\Test\HookInterface;
use Civi\Test\TransactionalInterface;
use CRM_Core_DAO;
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
 * @group headless
 */
class FillWMFDonorTest extends TestCase implements HeadlessInterface, HookInterface, TransactionalInterface {

  use Api3TestTrait;

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
   * The tearDown() method is executed after the test was executed (optional).
   *
   * This can be used for cleanup.
   *
   * @todo - this is the same as ArchiveThankYouTest - might be
   * time to introduce a shared parent soon.
   *
   * @throws \API_Exception
   */
  public function tearDown(): void {
    Contribution::delete(FALSE)->addWhere('contact_id.display_name', '=', 'Billy Bill')->execute();
    Contact::delete(FALSE)->addWhere('display_name', '=', 'Billy Bill')->setUseTrash(FALSE)->execute();
    parent::tearDown();
  }

  /**
   * @throws \Civi\API\Exception\UnauthorizedException
   * @throws \API_Exception
   */
  public function testFillWMFDonor(): void {
    $contactID = Contact::create(FALSE)->setValues(['first_name' => 'Billy', 'last_name' => 'Bill', 'contact_type' => 'Individual'])->execute()->first()['id'];
    Contribution::create(FALSE)->setValues([
      'receive_date' => '2021-08-02',
      'financial_type_id:name' => 'Donation',
      'total_amount' => 1,
      'contact_id' => $contactID,
    ])->execute();
    CRM_Core_DAO::executeQuery('DELETE FROM wmf_donor WHERE entity_id = ' . $contactID);
    WMFDataManagement::updateWMFDonor(FALSE)->execute();
    $total = Contact::get(FALSE)->addWhere('id', '=', $contactID)
      ->addSelect('wmf_donor.total_2021_2022')
      ->execute()->first()['wmf_donor.total_2021_2022'];
    $this->assertEquals(1, $total);

  }

}

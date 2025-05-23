<?php

namespace Civi\WMF;

use Civi\Api4\Activity;
use Civi\Api4\Contact;
use Civi\Api4\Contribution;
use Civi\Api4\WMFDataManagement;
use Civi\Test;
use Civi\Test\Api3TestTrait;
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
 * @group headless
 */
class ArchiveThankYouTest extends TestCase implements HeadlessInterface, HookInterface, TransactionalInterface {

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
   * @throws \CRM_Core_Exception
   */
  public function tearDown(): void {
    Contribution::delete(FALSE)->addWhere('contact_id.display_name', '=', 'Billy Bill')->execute();
    Contact::delete(FALSE)->addWhere('display_name', '=', 'Billy Bill')->setUseTrash(FALSE)->execute();
    parent::tearDown();
  }

  /***
   * Test purging old thank you details
   *
   * @throws \CRM_Core_Exception
   */
  public function testArchiveThankYou(): void {
    $contactID = Contact::create(FALSE)
      ->setValues(['first_name' => 'Billy', 'last_name' => 'Bill', 'contact_type' => 'Individual'])
      ->execute()->first()['id'];
    $activityID = Activity::create(FALSE)->setValues([
      'activity_type_id:name' => 'Email',
      'activity_date_time' => '2 years ago',
      'source_contact_id' => $contactID,
      'subject' => 'Your € 11.00 gift = free knowledge for billions',
      'details' => 'delete this please',
    ])->execute()->first()['id'];
    WMFDataManagement::archiveThankYou(FALSE)
      ->setLimit(1)
      ->execute();
    $activity = Activity::get(FALSE)
      ->addWhere('id', '=', $activityID)
      ->addSelect('details', 'subject')
      ->execute()->first();
    $this->assertEquals('', $activity['details']);
    $this->assertEquals('Your € 11.00 gift = free knowledge for billions', $activity['subject']);
  }

}

<?php

use Civi\Test\HeadlessInterface;
use Civi\Test\TransactionalInterface;

/**
 * Tests for SmashPig recurring payment scheduler
 *
 * Tips:
 *  - With HookInterface, you may implement CiviCRM hooks directly in the test
 * class. Simply create corresponding functions (e.g. "hook_civicrm_post(...)"
 * or similar).
 *  - With TransactionalInterface, any data changes made by setUp() or
 * test****() functions will rollback automatically -- as long as you don't
 * manipulate schema or truncate tables. If this test needs to manipulate
 * schema or truncate tables, then either: a. Do all that using setupHeadless()
 * and Civi\Test. b. Disable TransactionalInterface, and handle all
 * setup/teardown yourself.
 *
 * @group SmashPig
 * @group headless
 */
class CRM_SchedulerTest extends \PHPUnit_Framework_TestCase implements HeadlessInterface, TransactionalInterface {

  public function setUpHeadless() {
    // Civi\Test has many helpers, like install(), uninstall(), sql(), and sqlFile().
    // See: https://github.com/civicrm/org.civicrm.testapalooza/blob/master/civi-test.md
    return \Civi\Test::headless()
      ->installMe(__DIR__)
      ->apply();
  }

  public function setUp() {
    parent::setUp();
    if (!isset($GLOBALS['_PEAR_default_error_mode'])) {
      // This is simply to protect against e-notices if globals have been reset by phpunit.
      $GLOBALS['_PEAR_default_error_mode'] = NULL;
      $GLOBALS['_PEAR_default_error_options'] = NULL;
    }
  }

  public function testFirstOfMonth() {
    $record = [
      'cycle_day' => '1',
      'frequency_interval' => '1',
    ];
    $nextChargeDate = CRM_Core_Payment_Scheduler::getNextDateForMonth(
      $record, gmmktime(0, 0, 0, 1, 1, 2018)
    );
    $this->assertEquals('2018-02-01 00:00:00', $nextChargeDate);
  }

  /**
   * If someone's cycle date is the 31st, schedule their February
   * donation for the 28th
   */
  public function testNextMonthWithoutCycleDay() {
    $record = [
      'cycle_day' => '31',
      'frequency_interval' => '1',
    ];
    $nextChargeDate = CRM_Core_Payment_Scheduler::getNextDateForMonth(
      $record, gmmktime(0, 0, 0, 1, 31, 2018)
    );
    $this->assertEquals('2018-02-28 00:00:00', $nextChargeDate);
  }

  /**
   * In February, we charge someone with cycle_day = 31 on the 28th.
   * Make sure the next payment is scheduled for March 31.
   */
  public function testThisMonthWithoutCycleDay() {
    $record = [
      'cycle_day' => '31',
      'frequency_interval' => '1',
    ];
    $nextChargeDate = CRM_Core_Payment_Scheduler::getNextDateForMonth(
      $record, gmmktime(0, 0, 0, 2, 28, 2018)
    );
    $this->assertEquals('2018-03-31 00:00:00', $nextChargeDate);
  }

  public function testDecember() {
    $record = [
      'cycle_day' => '9',
      'frequency_interval' => '1',
    ];
    $nextChargeDate = CRM_Core_Payment_Scheduler::getNextDateForMonth(
      $record, gmmktime(0, 0, 0, 12, 9, 2017)
    );
    $this->assertEquals('2018-01-09 00:00:00', $nextChargeDate);
  }

  /**
   * If we've charged someone late this month, keep next month's donation
   * on the normal cycle day.
   */
  public function testOverduePayment() {
    $record = [
      'cycle_day' => '1',
      'frequency_interval' => '1',
    ];
    $nextChargeDate = CRM_Core_Payment_Scheduler::getNextDateForMonth(
      $record, gmmktime(0, 0, 0, 1, 9, 2018)
    );
    $this->assertEquals('2018-02-01 00:00:00', $nextChargeDate);
  }
}

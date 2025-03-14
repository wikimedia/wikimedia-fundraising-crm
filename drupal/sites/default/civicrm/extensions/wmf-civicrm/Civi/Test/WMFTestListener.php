<?php

namespace Civi\Test;

use Civi\Api4\Monolog;
use Civi\MonoLog\MonologManager;

/**
 * Class WMFTestListener
 * @package Civi\Test
 *
 * Copied from & cut down from CiviTestListener - this implements a
 * couple of things that are in WMFEnvironmentTrait but might not
 * be called from all tests - eg. ones that ship with
 * shared extensions like deduper are standalone from WMF
 * test code. When they are run in a non-WMF Ci the CiviTestListener
 * will do some of the set-up / tearDown. They don't get that in
 * our environment. Notably if their clean-up relies on transaction
 * rollback we might miss out on that. (I kinda hate transaction rollback
 * cos it's a pain to debug but even if we decided never to use it we
 * run tests maintained by non-WMF people).
 *
 * Notable features
 * - ensuring max_execution_time is reset for all tests.
 * - ensuring time is reset if frozen.
 * - implementing any transaction rollback.
 *
 * @see EndToEndInterface
 * @see HeadlessInterface
 * @see HookInterface
 */
class WMFTestListener implements \PHPUnit\Framework\TestListener {

  use \PHPUnit\Framework\TestListenerDefaultImplementation;

  /**
   * @var \CRM_Core_Transaction|null
   */
  private $tx;

  private array $originalStatic;

  public function endTestSuite(\PHPUnit\Framework\TestSuite $suite): void {}

  public function startTest(\PHPUnit\Framework\Test $test): void {
    error_reporting(E_ALL);
    $this->originalStatic = \Civi::$statics;
    $GLOBALS['CIVICRM_TEST_CASE'] = $test;
    \CRM_Core_Session::singleton()->set('userID', NULL);
    if ($test instanceof TransactionalInterface) {
      $this->tx = new \CRM_Core_Transaction(TRUE);
      $this->tx->rollback();
    }
    else {
      $this->tx = NULL;
    }
    // Our main test logger has a higher weight than the other loggers
    // and runs first, blocking them.
    \CRM_Core_DAO::executeQuery('UPDATE civicrm_monolog SET is_active = 1 WHERE type = "test"');
    set_time_limit(210);
  }

  public function endTest(\PHPUnit\Framework\Test $test, float $time): void {
    \CRM_Core_DAO::executeQuery('UPDATE civicrm_monolog SET is_active = 0 WHERE type = "test"');
    MonologManager::flush();
    if ($test instanceof TransactionalInterface) {
      $this->tx->rollback()->commit();
      $this->tx = NULL;
    }
    \CRM_Utils_Time::resetTime();
    unset($GLOBALS['CIVICRM_TEST_CASE']);
    \Civi::$statics = $this->originalStatic;
  }

}

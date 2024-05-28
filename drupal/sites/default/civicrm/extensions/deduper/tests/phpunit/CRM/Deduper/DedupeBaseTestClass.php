<?php

use Civi\Test\HeadlessInterface;
use Civi\Test\HookInterface;
use Civi\Test\TransactionalInterface;

class DedupeBaseTestClass extends \PHPUnit\Framework\TestCase implements HeadlessInterface, HookInterface, TransactionalInterface {

  use \Civi\Test\Api3TestTrait;

  protected $ids = [];

  /**
   * Set up for headless tests.
   *
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

  public function setUp(): void {
    parent::setUp();
    civicrm_initialize();
  }

  /**
   * @throws \CRM_Core_Exception
   */
  public function tearDown(): void {
    foreach ($this->ids as $entity => $ids) {
      foreach ($ids as $id) {
        if ($entity === 'contact') {
          foreach ($this->callAPISuccess('Contribution', 'get', ['contact_id' => $id])['values'] as $contribution) {
            civicrm_api3('Contribution', 'delete', ['id' => $contribution['id']]);
          }
        }
        civicrm_api3($entity, 'delete', ['id' => $id, 'skip_undelete' => TRUE]);
      }
    }
    parent::tearDown();
  }
}

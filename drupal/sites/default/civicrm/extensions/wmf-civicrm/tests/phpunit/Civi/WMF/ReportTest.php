<?php

namespace Civi\WMF;

use Civi\Test;
use Civi\Test\Api3TestTrait;
use Civi\Test\HeadlessInterface;
use Civi\Test\HookInterface;
use Civi\Test\TransactionalInterface;
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
class ReportTest extends TestCase implements HeadlessInterface, HookInterface, TransactionalInterface {

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

  /***
   * Basic test on gateway reconcilliation.
   *
   * @throws \CRM_Core_Exception
   */
  public function testGatewayReconciliationReport(): void {
    $params = [
      'report_id' => 'contribute/reconciliation',
      'fields' => [
        'total_amount' => '1',
        'is_negative' => '1',
        'financial_trxn_payment_instrument_id' => '1',
        'original_currency' => '1',
        'gateway' => '1',
        'gateway_account' => '1',
      ],
      'group_bys' =>
        [
          'is_negative' => '1',
          'original_currency' => '1',
          'gateway' => '1',
        ],
    ];
    $this->callAPISuccess('report_template', 'getrows', $params);
  }

}

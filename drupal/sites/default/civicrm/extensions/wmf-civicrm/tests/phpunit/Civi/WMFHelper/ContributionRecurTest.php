<?php

namespace Civi\WMFHelper;

use Civi\Test;
use Civi\Test\CiviEnvBuilder;
use Civi\Test\HeadlessInterface;
use Civi\Test\HookInterface;
use Civi\Test\TransactionalInterface;
use PHPUnit\Framework\TestCase;
use SmashPig\Tests\TestingContext;
use SmashPig\Tests\TestingGlobalConfiguration;

class ContributionRecurTest extends TestCase implements HeadlessInterface, HookInterface, TransactionalInterface {

  public function setUpHeadless(): CiviEnvBuilder {
    // Civi\Test has many helpers, like install(), uninstall(), sql(), and sqlFile().
    // See: https://docs.civicrm.org/dev/en/latest/testing/phpunit/#civitest
    return Test::headless()
      ->installMe(__DIR__)
      ->apply();
  }

  public function testGatewayManagesOwnRecurringSchedule(): void {
    // Initialize SmashPig with a fake context object
    // @todo - could we move this to bootstrap? Or perhaps have some way
    // it always loads in unit tests - this feels too low level to have
    $globalConfig = TestingGlobalConfiguration::create();
    TestingContext::init($globalConfig);
    $this->assertTrue(ContributionRecur::gatewayManagesOwnRecurringSchedule('paypal_ec'));
    $this->assertFalse(ContributionRecur::gatewayManagesOwnRecurringSchedule('adyen'));
    $this->assertFalse(ContributionRecur::gatewayManagesOwnRecurringSchedule('dlocal'));
    $this->assertFalse(ContributionRecur::gatewayManagesOwnRecurringSchedule('fundraiseup'));
  }

}

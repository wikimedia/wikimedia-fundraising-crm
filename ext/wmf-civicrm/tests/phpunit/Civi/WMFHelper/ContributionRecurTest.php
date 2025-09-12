<?php

namespace Civi\WMFHelper;

use Civi\Test\HeadlessInterface;
use Civi\Test\HookInterface;
use Civi\Test\TransactionalInterface;
use Civi\WMFEnvironmentTrait;
use PHPUnit\Framework\TestCase;
use SmashPig\Tests\TestingContext;
use SmashPig\Tests\TestingGlobalConfiguration;

class ContributionRecurTest extends TestCase implements HeadlessInterface, HookInterface, TransactionalInterface {

  use WMFEnvironmentTrait;

  public function testGatewayManagesOwnRecurringSchedule(): void {
    // Initialize SmashPig with a fake context object
    // @todo - could we move this to bootstrap? Or perhaps have some way
    // it always loads in unit tests - this feels too low level to have
    $globalConfig = TestingGlobalConfiguration::create();
    TestingContext::init($globalConfig);
    $this->assertTrue(ContributionRecur::gatewayManagesOwnRecurringSchedule('paypal_ec'));
    $this->assertFalse(ContributionRecur::gatewayManagesOwnRecurringSchedule('adyen'));
    $this->assertFalse(ContributionRecur::gatewayManagesOwnRecurringSchedule('dlocal'));
  }

}

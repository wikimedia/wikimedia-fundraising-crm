<?php
declare(strict_types = 1);
namespace Civi\AuthenticatedIframe;

use Civi\Test\CiviEnvBuilder;
use Civi\Test\HeadlessInterface;
use Civi\Test\TransactionalInterface;

/**
 * @group headless
 */
abstract class AuthenticatedIframeTestCase extends \PHPUnit\Framework\TestCase implements HeadlessInterface, TransactionalInterface {

  use AuthenticatedIframeTestTrait;

  public function setUpHeadless(): CiviEnvBuilder {
    return \Civi\Test::headless()
      ->installMe(__DIR__)
      ->apply();
  }

  /**
   * Role/UserRole grants from grantPermissions() are already rolled
   * back by TransactionalInterface.
   */
  public function tearDown(): void {
    $this->resetIframeAuth();
    parent::tearDown();
  }

}

<?php
declare(strict_types = 1);
namespace Civi\AuthenticatedIframe\Page;

use Civi\AuthenticatedIframe\IframePage;

/**
 * Test-only page, invoked by RouteAuthTest via a civicrm_menu row it stores.
 */
class ExampleFixture extends IframePage {

  protected function getHandshakeParams(): array {
    return ['example'];
  }

  protected function handlePost(): string {
    return 'authenticatediframe example page reached';
  }

}

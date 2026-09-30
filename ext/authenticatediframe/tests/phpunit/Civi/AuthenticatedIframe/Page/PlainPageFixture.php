<?php
declare(strict_types = 1);
namespace Civi\AuthenticatedIframe\Page;

/**
 * Test-only page that doesn't extend IframePage, which AccessGuard denies.
 */
class PlainPageFixture extends \CRM_Core_Page {

  public function run() {
    echo 'authenticatediframe plain page reached';
  }

}

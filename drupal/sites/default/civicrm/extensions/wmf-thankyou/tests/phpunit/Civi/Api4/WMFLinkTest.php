<?php
namespace Civi\Api4;

use PHPUnit\Framework\TestCase;

class WMFLinkTest extends TestCase {

  /**
   * Test getting the unsubscribe link.
   *
   * Ensure that contribution_id is set to -1 if not provided
   * - or more specifically that the calling function does not have to
   * provide a dummy value and any dummy values will be provided by the api.
   *
   * @return void
   * @throws \CRM_Core_Exception
   */
  public function testGetUnsubscribeLink(): void {
    $link = WMFLink::getUnsubscribeURL(FALSE)
      ->setEmail('me@example.com')
      ->setLanguage('es_ES')
      ->execute()->first()['unsubscribe_url'];
    $this->assertStringContainsString('p=thankyou', $link);
    $this->assertStringContainsString('c=-1', $link);
    $this->assertStringContainsString('e=me%40example.com', $link);
    $this->assertStringContainsString('uselang=es', $link);
    $this->assertStringContainsString(\Civi::settings()->get('wmf_unsubscribe_url') , $link);
  }

}

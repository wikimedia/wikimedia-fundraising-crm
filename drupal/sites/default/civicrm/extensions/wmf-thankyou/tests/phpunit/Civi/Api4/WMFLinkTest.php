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
      ->setContactID(215)
      ->execute()->first()['unsubscribe_url'];
    $this->assertStringContainsString('checksum=', $link);
    $this->assertStringContainsString('contact_id=215', $link);
    $this->assertStringContainsString(\Civi::settings()->get('wmf_email_preferences_url') , $link);
  }

}

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
    $links = WMFLink::getUnsubscribeURL(FALSE)
      ->setEmail('me@example.com')
      ->setLanguage('es_ES')
      ->setContactID(215)
      ->setContributionID(123456)
      ->execute()->first();
    $hash = sha1(215 . 'me@example.com'
      . \CRM_Utils_Constant::value('WMF_UNSUB_SALT')
    );
    $link = $links['unsubscribe_url'];
    $this->assertStringContainsString('checksum=', $link);
    $this->assertStringContainsString('email=me%40example.com', $link);
    $this->assertStringContainsString('hash='.$hash , $link);
    $this->assertStringContainsString('contact_id=215', $link);
    $this->assertStringContainsString('uselang=es', $link);
    $this->assertStringContainsString(\Civi::settings()->get('wmf_email_preferences_url') , $link);

    $oldlink = $links['unsubscribe_url_old'];
    $this->assertStringContainsString('p=thankyou', $oldlink);
    $this->assertStringContainsString('c=123456', $oldlink);
    $this->assertStringContainsString('e=me%40example.com', $oldlink);
    $this->assertStringContainsString('uselang=es', $oldlink);
    $this->assertStringContainsString(\Civi::settings()->get('wmf_unsubscribe_url') , $oldlink);
  }

}

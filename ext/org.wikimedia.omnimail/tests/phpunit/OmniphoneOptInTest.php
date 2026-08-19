<?php

namespace phpunit;

use Civi\Api4\Omniphone;
use OmnimailBaseTestClass;

require_once __DIR__ . '/OmnimailBaseTestClass.php';

/**
 * Test opting a phone number into an SMS program at Acoustic.
 *
 * @group headless
 */
class OmniphoneOptInTest extends OmnimailBaseTestClass {

  /**
   * Test a virtual mobile originated message is sent to the configured program.
   */
  public function testOptIn(): void {
    $this->setSetting('omnimail_sms_campaign_id', '99887766');
    $this->getMockRequest(['{"status": "success"}']);

    $result = Omniphone::optIn(FALSE)
      ->setClient($this->getGuzzleClient())
      ->setPhoneNumber('13151234567')
      ->execute()->first();

    $this->assertEquals('https://api-campaign-us-4.goacoustic.com/rest/channels/sms/programs/99887766/virtualmo', $this->getRequestUrls()[0]);
    $this->assertEquals('{"phoneNumber":"13151234567"}', $this->getRequestBodies()[0]);
    $this->assertEquals('success', $result['status']);
  }

}

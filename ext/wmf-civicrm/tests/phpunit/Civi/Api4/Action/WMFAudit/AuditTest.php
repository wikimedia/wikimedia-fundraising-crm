<?php

namespace Civi\Api4\Action\WMFAudit;

use Civi\Api4\WMFAudit;
use Civi\Test\EntityTrait;
use Civi\WMFEnvironmentTrait;
use PHPUnit\Framework\TestCase;

/**
 * WMF Audit test.
 *
 * The Audit test class is responsible for processing the message onto the correct queue.
 */
class AuditTest extends TestCase {
  use WMFEnvironmentTrait;
  use EntityTrait;

  public function testAuditRefundMessage(): void {
    $message = [
      'contribution_tracking_id' => '43992337',
      'city' => 'asdf',
      'country' => 'US',
      'currency' => 'USD',
      'date' => 1487484651,
      'email' => 'mouse@wikimedia.org',
      'fee' => 0.24,
      'first_name' => 'asdf',
      'gateway' => 'adyen',
      'gateway_account' => 'TestMerchant',
      'gateway_txn_id' => '5364893193133131',
      'gross' => '1.00',
      'invoice_id' => '43992337.0',
      'language' => 'en',
      'last_name' => 'asdff',
      'order_id' => '43992337.0',
      'payment_method' => 'cc',
      'payment_submethod' => 'visa',
      'postal_code' => '11111',
      'recurring' => '',
      'state_province' => 'AK',
      'street_address' => 'asdf',
      'user_ip' => '77.177.177.77',
      'utm_campaign' => 'C13_en.wikipedia.org',
      'utm_medium' => 'sidebar',
      'utm_source' => '..cc',
      'settled_gross' => '0.76',
      'settled_currency' => 'USD',
      'settled_fee' => 0.24,
      'tracking_date' => '2017-02-19 06:10:51',
    ];
    $audit = WMFAudit::audit(FALSE)
      ->setValues($message)->execute()->first();
    $this->assertEquals(TRUE, $audit['is_missing']);

  }

}

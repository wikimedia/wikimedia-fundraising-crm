<?php

namespace Civi\WMFQueueMessage;

use Civi\Test\HeadlessInterface;
use Civi\WMFEnvironmentTrait;
use PHPUnit\Framework\TestCase;

class DonationMessageTest extends TestCase implements HeadlessInterface {

  use WMFEnvironmentTrait;

  public function testDoubleNormalization(): void {
    // Start with a message already in normal form, to make comparison easy
    $enqueuedTime = time() + 2;
    $original_msg = [
      'city' => '',
      'comment' => '',
      'contact_id' => mt_rand(),
      'contribution_recur_id' => mt_rand(),
      'contribution_tracking_id' => mt_rand(),
      'financial_type_id' => 9,
      'country' => 'IL',
      'create_date' => time() + 11,
      'currency' => 'USD',
      'date' => time() + 1,
      'effort_id' => '2',
      'email' => 'test.es@localhost.net',
      'fee' => 0.5,
      'first_name' => 'test',
      'gateway' => 'paypal',
      'gateway_txn_id' => '1234AB1234-2',
      'gross' => 5.8,
      'last_name' => 'es',
      'middle_name' => '',
      'net' => 5.29,
      'order_id' => mt_rand(),
      'organization_name' => '',
      'original_currency' => 'ILS',
      'original_gross' => 20.00,
      'payment_date' => time(),
      'payment_instrument_id' => 25,
      'payment_instrument' => 'Paypal',
      'postal_code' => '',
      'recurring' => '1',
      'source_enqueued_time' => $enqueuedTime,
      'contribution_extra.source_enqueued_time' => $enqueuedTime,
      'source_host' => 'thulium',
      'contribution_extra.source_host' => 'thulium',
      'source_name' => 'PayPal IPN (legacy)',
      'contribution_extra.source_name' => 'PayPal IPN (legacy)',
      'source_run_id' => 9999998888877777,
      'contribution_extra.source_run_id' => 9999998888877777,
      'source_type' => 'listener',
      'contribution_extra.source_type' => 'listener',
      'source_version' => 'legacy',
      'contribution_extra.source_version' => 'legacy',
      'start_date' => time() + 10,
      'state_province' => '',
      'street_address' => '',
      'subscr_id' => 'TEST-S-1234567' . mt_rand(),
      'supplemental_address_1' => '',
      'supplemental_address_2' => '',
      'trxn_id' => 'RECURRING PAYPAL 1234AB1234-2',
      'txn_type' => 'subscr_payment',
      'utm_campaign' => '',
      'contribution_extra.gateway_txn_id' => '1234AB1234-2',
      'Gift_Data.Appeal' => '',
      'Gift_Data.Channel' => 'Recurring Gift',
      'contribution_extra.original_amount' => '20',
      'contribution_extra.original_currency' => 'ILS',
      'contribution_extra.gateway' => 'paypal',
      'Gift_Data.is_major_gift' => FALSE,
    ];

    $msg = $original_msg;
    $message = new DonationMessage($msg);
    $normal_msg_1 = $message->normalize();
    unset($original_msg['middle_name']);
    $this->assertEquals($original_msg, $normal_msg_1);
    $message = new DonationMessage($normal_msg_1);
    $normal_msg_2 = $message->normalize();
    $this->assertEquals($original_msg, $normal_msg_2);
  }

  public function testGetPaymentInstrumentReturnNullInNormalizeMsg(): void {
    // Initialize message with no payment_instrument_id, payment_instrument, gateway, and payment_method set.
    $original_msg = [
      'city' => '',
      'comment' => '',
      'contact_id' => mt_rand(),
      'contribution_recur_id' => mt_rand(),
      'contribution_tracking_id' => mt_rand(),
      'financial_type_id' => '9',
      'country' => 'IL',
      'create_date' => time() + 11,
      'currency' => 'USD',
      'date' => time() + 1,
      'effort_id' => '2',
      'email' => 'test.es@localhost.net',
      'fee' => 0.5,
      'first_name' => 'test',
      'gateway' => 'UNKNOWN',
      'gateway_txn_id' => '1234AB1234-2',
      'gross' => 5.8,
      'last_name' => 'es',
      'middle_name' => '',
      'net' => 5.29,
      'order_id' => mt_rand(),
      'organization_name' => '',
      'original_currency' => 'ILS',
      'original_gross' => '20.00',
      'payment_date' => time(),
      'postal_code' => '',
      'recurring' => '1',
      'source_enqueued_time' => time() + 2,
      'source_host' => 'thulium',
      'source_name' => 'PayPal IPN (legacy)',
      'source_run_id' => mt_rand(),
      'source_type' => 'listener',
      'source_version' => 'legacy',
      'start_date' => time() + 10,
      'state_province' => '',
      'street_address' => '',
      'subscr_id' => 'TEST-S-1234567' . mt_rand(),
      'supplemental_address_1' => '',
      'supplemental_address_2' => '',
      'txn_type' => 'subscr_payment',
      'utm_campaign' => '',
    ];

    $msg = $original_msg;
    $message = new DonationMessage($msg);
    $message->normalize();
    $this->assertNull($message->getPaymentInstrumentID());
  }

  public function testEmptyNet(): void {
    $msg = [
      'gateway' => 'adyen',
      'payment_method' => 'cc',
      'payment_submethod' => 'visa',
      'first_name' => 'blah',
      'last_name' => 'wah',
      'country' => 'US',
      'currency' => 'USD',
      'gross' => '1.00',
      'net' => '',
      'fee' => '0.21',
    ];
    $message = new DonationMessage($msg);
    $normalized = $message->normalize();
    $this->assertEquals(0.79, $normalized['net']);
  }

}

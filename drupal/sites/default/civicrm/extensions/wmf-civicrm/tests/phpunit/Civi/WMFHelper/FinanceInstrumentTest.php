<?php

use Civi\Test;
use Civi\Test\HeadlessInterface;
use Civi\WMFHelper\FinanceInstrument;
use PhpUnit\Framework\TestCase;

class FinanceInstrumentTest extends TestCase implements HeadlessInterface {

  /**
   * @return \Civi\Test\CiviEnvBuilder
   * @throws \CRM_Extension_Exception_ParseException
   */
  public function setUpHeadless(): Test\CiviEnvBuilder {
    // Civi\Test has many helpers, like install(), uninstall(), sql(), and sqlFile().
    // See: https://docs.civicrm.org/dev/en/latest/testing/phpunit/#civitest
    return Test::headless()
      ->installMe(__DIR__)
      ->apply();
  }

  public function testAdyenApplepay() {
    $msg = array(
      'gateway' => 'adyen',
      'payment_method' => 'apple',
      'payment_submethod' => 'visa',
      'first_name' => 'blah',
      'last_name' => 'wah',
      'country' => 'US',
      'currency' => 'USD',
      'gross' => '1.00',
      'net' => '',
      'fee' => '0.21',
    );
    $payment_instrument = FinanceInstrument::getPaymentInstrument( $msg );
    $this->assertEquals( 'Apple Pay: Visa', $payment_instrument );
  }

  public function testAdyenGooglepay() {
    $msg = array(
      'gateway' => 'adyen',
      'payment_method' => 'google',
      'payment_submethod' => 'visa',
      'first_name' => 'blah',
      'last_name' => 'wah',
      'country' => 'US',
      'currency' => 'USD',
      'gross' => '1.00',
      'net' => '',
      'fee' => '0.21',
    );
    $payment_instrument = FinanceInstrument::getPaymentInstrument( $msg );
    $this->assertEquals( 'Google Pay: Visa', $payment_instrument );
  }

  public function testBTPaymentInstrument() {
    $msg = array(
      'gateway' => 'pix',
      'payment_method' => 'bt',
      'payment_submethod' => 'banco_do_brasil',
      'first_name' => 'blah',
      'last_name' => 'wah',
      'country' => 'US',
      'currency' => 'USD',
      'gross' => '1.00',
      'net' => '',
      'fee' => '0.21',
    );
    $payment_instrument = FinanceInstrument::getPaymentInstrument( $msg );
    $this->assertEquals( 'Bank Transfer: Banco do Brasil', $payment_instrument );
  }

  public function testCCPaymentInstrument() {
    $msg = array(
      'gateway' => 'adyen',
      'payment_method' => 'cc',
      'payment_submethod' => 'cb',
      'first_name' => 'blah',
      'last_name' => 'wah',
      'country' => 'US',
      'currency' => 'USD',
      'gross' => '1.00',
      'net' => '',
      'fee' => '0.21',
    );
    $payment_instrument = FinanceInstrument::getPaymentInstrument( $msg );
    $this->assertEquals( 'Credit Card: Carte Bleue', $payment_instrument );
  }

  public function testOBTPaymentInstrument() {
    $msg = array(
      'payment_method' => 'obt',
      'payment_submethod' => 'bpay',
      'first_name' => 'blah',
      'last_name' => 'wah',
      'country' => 'US',
      'currency' => 'USD',
      'gross' => '1.00',
      'net' => '',
      'fee' => '0.21',
    );
    $payment_instrument = FinanceInstrument::getPaymentInstrument( $msg );
    $this->assertEquals( 'Bpay', $payment_instrument );
  }

  public function testRTBTPaymentInstrument() {
    $msg = array(
      'payment_method' => 'rtbt',
      'payment_submethod' => 'rtbt_nordea_sweden',
      'first_name' => 'blah',
      'last_name' => 'wah',
      'country' => 'US',
      'currency' => 'USD',
      'gross' => '1.00',
      'net' => '',
      'fee' => '0.21',
    );
    $payment_instrument = FinanceInstrument::getPaymentInstrument( $msg );
    $this->assertEquals( 'Nordea', $payment_instrument );
  }

  public function testCashPaymentInstrument() {
    $msg = array(
      'payment_method' => 'cash',
      'payment_submethod' => 'cash_abitab',
      'first_name' => 'blah',
      'last_name' => 'wah',
      'country' => 'US',
      'currency' => 'USD',
      'gross' => '1.00',
      'net' => '',
      'fee' => '0.21',
    );
    $payment_instrument = FinanceInstrument::getPaymentInstrument( $msg );
    $this->assertEquals( 'Abitab', $payment_instrument );
  }
}

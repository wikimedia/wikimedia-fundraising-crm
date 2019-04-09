<?php

/**
 * @group WmfCivicrm
 * @group WmfCivicrmHelpers
 */
class HelperFunctionsTest extends BaseWmfDrupalPhpUnitTestCase {

    /**
     * Test wmf_ensure_language_exists
     *
     * If that ever gets fixed it may break this test - but only the test would
     * need to be altered to adapt.
     *
     * @throws \CiviCRM_API3_Exception
     */
    public function testEnsureLanguageExists() {
        civicrm_initialize();
        wmf_civicrm_ensure_language_exists('en_IL');
        $language = $this->callAPISuccessGetSingle('OptionValue', [
          'option_group_name' => 'languages',
          'name' => 'en_IL',
        ]);

        $this->callAPISuccess('OptionValue', 'create', ['id' => $language['id'], 'is_active' => 0]);
        wmf_civicrm_ensure_language_exists('en_IL');

        $this->callAPISuccessGetSingle('OptionValue', [
          'option_group_name' => 'languages',
          'name' => 'en_IL',
        ]);
    }

  /**
   * Test that the payment instrument is converted to an id.
   *
   * Use a high number to ensure the default 25 limit does not hurt us.
   */
    public function testGetCiviID() {
      civicrm_initialize();
      $paymentMethodID = wmf_civicrm_get_civi_id('payment_instrument_id', 'Trilogy');
      $this->assertTrue(is_numeric($paymentMethodID));
    }

  /**
   * Test that the payment instrument is converted to an id.
   *
   * Use a high number to ensure the default 25 limit does not hurt us.
   */
  public function testGetInvalidCiviID() {
    civicrm_initialize();
    $paymentMethodID = wmf_civicrm_get_civi_id('payment_instrument_id', 'Monopoly money');
    $this->assertEquals(FALSE, $paymentMethodID);
  }

    public function testParseWatchdogLog() {
        $logLine = "Apr 21 17:00:02 mach1001 drupal: RecurringQueueConsumer|1492791234|127.0.0.1|https://example.wikimedia.org/index.php||1||Array#012(#012    [date] => 1492791234#012    [txn_type] => subscr_payment#012    [gateway_txn_id] => 1X123456TJ0987654#012    [currency] => EUR#012    [contribution_tracking_id] => 47012345#012    [email] => donor@generous.com#012    [first_name] => DONNY#012    [last_name] => DONOR#012    [street_address] => 123 GOLDFISH POND RD#012    [city] => LONDON#012    [state_province] => #012    [country] => GB#012    [postal_code] => 1000#012    [gross] => 10.00#012    [fee] => 0.62#012    [order_id] => 47012345#012    [recurring] => 1#012    [subscr_id] => S-9TN12345BR9987654#012    [middle_name] => #012    [gateway] => paypal#012    [source_name] => SmashPig#012    [source_type] => listener#012    [source_host] => mach1001#012    [source_run_id] => 113106#012    [source_version] => 200f63eedb05f5e6665d9837bba97e7e7237a41d#012    [source_enqueued_time] => 1492793225#012)";
        $parsed = wmf_civicrm_parse_watchdog_array( $logLine );
        $expected = array(
            'date' => '1492791234',
            'txn_type' => 'subscr_payment',
            'gateway_txn_id' => '1X123456TJ0987654',
            'currency' => 'EUR',
            'contribution_tracking_id' => '47012345',
            'email' => 'donor@generous.com',
            'first_name' => 'DONNY',
            'last_name' => 'DONOR',
            'street_address' => '123 GOLDFISH POND RD',
            'city' => 'LONDON',
            'state_province' => '',
            'country' => 'GB',
            'postal_code' => '1000',
            'gross' => '10.00',
            'fee' => '0.62',
            'order_id' => '47012345',
            'recurring' => '1',
            'subscr_id' => 'S-9TN12345BR9987654',
            'middle_name' => '',
            'gateway' => 'paypal',
            'source_name' => 'SmashPig',
            'source_type' => 'listener',
            'source_host' => 'mach1001',
            'source_run_id' => '113106',
            'source_version' => '200f63eedb05f5e6665d9837bba97e7e7237a41d',
            'source_enqueued_time' => '1492793225',
        );
        $this->assertEquals( $expected, $parsed );
    }
}

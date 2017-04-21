<?php

/**
 * @group WmfCivicrm
 * @group WmfCivicrmHelpers
 */
class HelperFunctionsTest extends BaseWmfDrupalPhpUnitTestCase {

    /**
     * Test wmf_ensure_language_exists
     *
     * Maintenance note: the civicrm entity_tag get api returns an odd syntax.
     *
     * If that ever gets fixed it may break this test - but only the test would
     * need to be altered to adapt.
     *
     * @throws \CiviCRM_API3_Exception
     */
    public function testEnsureLanguageExists() {
        civicrm_initialize();
        wmf_civicrm_ensure_language_exists('en_IL');
        $languages = civicrm_api3('OptionValue', 'get', array(
            'option_group_name' => 'languages',
            'name' => 'en_IL',
        ));
        $this->assertEquals(1, $languages['count']);
    }

    /**
     * Test wmf custom api entity get detail.
     *
     * @todo consider moving test to thank_you module or helper function out of there.
     *
     * @throws \CiviCRM_API3_Exception
     */
    public function testGetEntityTagDetail() {
        civicrm_initialize();
        $contact = $this->callAPISuccess('Contact', 'create', array(
            'first_name' => 'Papa',
            'last_name' => 'Smurf',
            'contact_type' => 'Individual',
        ));
        $contribution = $this->callAPISuccess('Contribution', 'create', array(
            'contact_id' => $contact['id'],
            'total_amount' => 40,
            'financial_type_id' => 'Donation',
        ));

        $tag1 = $this->ensureTagExists('smurfy');
        $tag2 = $this->ensureTagExists('smurfalicious');

        $this->callAPISuccess('EntityTag', 'create', array('entity_id' => $contribution['id'], 'entity_table' => 'civicrm_contribution', 'tag_id' => 'smurfy'));
        $this->callAPISuccess('EntityTag', 'create', array('entity_id' => $contribution['id'], 'entity_table' => 'civicrm_contribution', 'tag_id' => 'smurfalicious'));

        $smurfiestTags = wmf_thank_you_get_tag_names($contribution['id']);
        $this->assertEquals(array('smurfy', 'smurfalicious'), $smurfiestTags);

        $this->callAPISuccess('Tag', 'delete', array('id' => $tag1));
        $this->callAPISuccess('Tag', 'delete', array('id' => $tag2));
    }

    /**
     * Helper function to protect test against cleanup issues.
     *
     * @param string $name
     * @return int
     */
    public function ensureTagExists($name) {
        $tags = $this->callAPISuccess('EntityTag', 'getoptions', array('field' => 'tag_id'));
        if (in_array($name, $tags['values'])) {
            return array_search($name, $tags['values']);
        }
        $tag = $this->callAPISuccess('Tag', 'create', array(
            'used_for' => 'civicrm_contribution',
            'name' => $name
        ));
        $this->callAPISuccess('Tag', 'getfields', array('cache_clear' => 1));
        return $tag['id'];
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

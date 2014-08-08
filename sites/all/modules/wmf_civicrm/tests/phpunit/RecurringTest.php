<?php

class RecurringTest extends BaseWmfDrupalPhpUnitTestCase {
    public static function getInfo() {
        return array(
            'name' => 'Recurring',
            'group' => 'Pipeline',
            'description' => 'Checks for recurring functionality',
        );
    }

    public function setUp() {
        parent::setUp();
        civicrm_initialize();
    }

    /**
     * Test next_sched_contribution calculation
     *
     * @dataProvider nextSchedProvider
     */
    public function testNextScheduled( $now, $cycle_day, $expected_next_sched ) {
        if ( defined( 'HHVM_VERSION' ) ) {
            throw new PHPUnit_Framework_SkippedTestError( 'Running under HHVM, skipping known failure' );
        }

        $msg = array(
            'cycle_day' => $cycle_day,
            'frequency_interval' => 1,
        );
        $nowstamp = strtotime($now);
        $calculated_next_sched = wmf_civicrm_get_next_sched_contribution_date_for_month( $msg, $nowstamp );

        $this->assertEquals( $expected_next_sched, $calculated_next_sched );
    }

    public function nextSchedProvider() {
        return array(
            array( '2014-06-01T00:00:00Z', '1', '2014-07-01 00:00:00' ),
            array( '2014-06-01T01:00:00Z', '1', '2014-07-01 00:00:00' ),
            array( '2014-06-01T02:00:00Z', '1', '2014-07-01 00:00:00' ),
            array( '2014-06-01T03:00:00Z', '1', '2014-07-01 00:00:00' ),
            array( '2014-06-01T04:00:00Z', '1', '2014-07-01 00:00:00' ),
            array( '2014-06-01T05:00:00Z', '1', '2014-07-01 00:00:00' ),
            array( '2014-06-01T06:59:00Z', '1', '2014-07-01 00:00:00' ),
            array( '2014-06-01T07:00:00Z', '1', '2014-07-01 00:00:00' ),
            array( '2014-06-01T07:01:00Z', '1', '2014-07-01 00:00:00' ),
            array( '2014-06-01T08:00:00Z', '1', '2014-07-01 00:00:00' ),
            array( '2014-06-01T09:00:00Z', '1', '2014-07-01 00:00:00' ),
            array( '2014-06-01T13:00:00Z', '1', '2014-07-01 00:00:00' ),
            array( '2014-06-01T14:00:00Z', '1', '2014-07-01 00:00:00' ),
            array( '2014-06-01T15:00:00Z', '1', '2014-07-01 00:00:00' ),
            array( '2014-06-01T16:00:00Z', '1', '2014-07-01 00:00:00' ),
        );
    }

    public function testGetGatewaySubscription() {
        // TODO: fixtures
        $result = civicrm_api3( 'Contact', 'create', array(
            'first_name' => 'Testes',
            'contact_type' => 'Individual',
        ) );
        $this->contact_id = $result['id'];

        $subscription_id_1 = 'SUB_FOO-' . mt_rand();
        $recur_values = array(
            'contact_id' => $this->contact_id,
            'amount' => '1.21',
            'frequency_interval' => 1,
            'frequency_unit' => 'month',
            'next_sched_contribution' => wmf_common_date_unix_to_civicrm(strtotime('+1 month')),
            'installments' => 0,
            'processor_id' => 1,
            'currency' => 'USD',
            'trxn_id' => "RECURRING TESTGATEWAY {$subscription_id_1}",
        );
        $result = civicrm_api3( 'ContributionRecur', 'create', $recur_values );

        $record = wmf_civicrm_get_gateway_subscription( 'TESTGATEWAY', $subscription_id_1 );

        $this->assertTrue( is_object( $record ),
            'Will match on full unique subscription ID' );
        $this->assertEquals( $recur_values['trxn_id'], $record->trxn_id );

        $subscription_id_2 = 'SUB_FOO-' . mt_rand();
        $recur_values['trxn_id'] = $subscription_id_2;
        $result = civicrm_api3( 'ContributionRecur', 'create', $recur_values );

        $record = wmf_civicrm_get_gateway_subscription( 'TESTGATEWAY', $subscription_id_2 );

        $this->assertTrue( is_object( $record ),
            'Will match raw subscription ID' );
        $this->assertEquals( $recur_values['trxn_id'], $record->trxn_id );
    }
}

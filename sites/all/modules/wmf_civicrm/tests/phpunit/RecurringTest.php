<?php

class RecurringTest extends BaseWmfDrupalPhpUnitTestCase {
    public static function getInfo() {
        return array(
            'name' => 'Recurring',
            'group' => 'Pipeline',
            'description' => 'Checks for recurring functionality',
        );
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
}

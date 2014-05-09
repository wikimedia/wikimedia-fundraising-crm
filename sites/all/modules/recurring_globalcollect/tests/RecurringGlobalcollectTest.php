<?php

class RecurringGlobalcollectTest extends BaseWmfDrupalPhpUnitTestCase {
    function setUp() {
        parent::setUp();
        civicrm_initialize();

        $this->original_standalone_globalcollect_adapter_path = variable_get( 'standalone_globalcollect_adapter_path', null );
        variable_set( 'standalone_globalcollect_adapter_path', __DIR__ . '/adapter' );

        $this->subscription_id = 'SUB-FOO-' . mt_rand();
        $this->amount = '1.12';

        $this->contributions = array();

        $result = civicrm_api3( 'Contact', 'create', array(
            'first_name' => 'Testes',
            'contact_type' => 'Individual',
        ) );
        $this->contact_id = $result['id'];

        $result = civicrm_api3( 'ContributionRecur', 'create', array(
            'contact_id' => $this->contact_id,
            'amount' => $this->amount,
            'frequency_interval' => 1,
            'frequency_unit' => 'month',
            'next_sched_contribution' => wmf_common_date_unix_to_civicrm(strtotime('+1 month')),
            'installments' => 0,
            'processor_id' => 1,
            'currency' => 'USD',
            'trxn_id' => "TESTGATEWAY {$this->subscription_id}",
        ) );
        $this->contribution_recur_id = $result['id'];

        $result = civicrm_api3( 'Contribution', 'create', array(
            'contact_id' => $this->contact_id,
            'contribution_recur_id' => $this->contribution_recur_id,
            'total_amount' => $this->amount,
            'contribution_type' => 'Cash',
            'payment_instrument' => 'Credit Card',
        ) );
        $this->contributions[] = $result['id'];
    }

    function tearDown() {
        variable_set( 'standalone_globalcollect_adapter_path', $this->original_standalone_globalcollect_adapter_path );

        foreach ( $this->contributions as $contribution_id ) {
            civicrm_api3( 'Contribution', 'delete', array(
                'id' => $contribution_id,
            ) );
        }
        civicrm_api3( 'ContributionRecur', 'delete', array(
            'id' => $this->contribution_recur_id,
        ) );
        civicrm_api3( 'Contact', 'delete', array(
            'id' => $this->contact_id,
        ) );
        parent::tearDown();
    }

    function testCharge() {
        $result = civicrm_api3( 'ContributionRecur', 'get', array(
            'id' => $this->contribution_recur_id,
        ) );
        $contribution_recur = array_pop( $result['values'] );

        $result = recurring_globalcollect_charge( $contribution_recur );
        $this->assertEquals( 'completed', $result['status'] );

        $result = civicrm_api3( 'Contribution', 'get', array(
            'contact_id' => $this->contact_id,
        ) );
        $this->assertEquals( 2, count( $result['values'] ) );
        foreach ( $result['values'] as $contribution ) {
            if ( $contribution['id'] == $this->contributions[0] ) {
                continue;
            }
            $this->contributions[] = $contribution['id'];
        }
    }
}

<?php

class RecurringGlobalcollectTest extends BaseWmfDrupalPhpUnitTestCase {
    function setUp() {
        parent::setUp();
        civicrm_initialize();

        $this->original_standalone_globalcollect_adapter_path = variable_get( 'standalone_globalcollect_adapter_path', null );
        variable_set( 'standalone_globalcollect_adapter_path', __DIR__ . '/includes' );

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
            'trxn_id' => "RECURRING TESTGATEWAY {$this->subscription_id}",
        ) );
        $this->contribution_recur_id = $result['id'];

        $result = civicrm_api3( 'Contribution', 'create', array(
            'contact_id' => $this->contact_id,
            'contribution_recur_id' => $this->contribution_recur_id,
            'currency' => 'USD',
            'total_amount' => $this->amount,
            'contribution_type' => 'Cash',
            'payment_instrument' => 'Credit Card',
            'trxn_id' => 'RECURRING GLOBALCOLLECT STUB_ORIG_CONTRIB-' . mt_rand(),
        ) );
        $this->contributions[] = $result['id'];
		wmf_civicrm_insert_contribution_tracking( '..rcc', 'civicrm', wmf_common_date_unix_to_sql( strtotime( 'now' ) ), $result['id'] );
	}

    function tearDown() {
        variable_set( 'standalone_globalcollect_adapter_path', $this->original_standalone_globalcollect_adapter_path );
        parent::tearDown();
    }

    function testCharge() {
        $result = recurring_globalcollect_charge( $this->contribution_recur_id );
        $this->assertEquals( 'completed', $result['status'] );

        $result = civicrm_api3( 'Contribution', 'get', array(
            'contact_id' => $this->contact_id,
        ) );
        $this->assertEquals( 2, count( $result['values'] ) );
        foreach ( $result['values'] as $contribution ) {
            if ( $contribution['id'] == $this->contributions[0] ) {
                // Skip assertions on the synthetic original contribution
                continue;
            }

            $this->assertEquals( 1,
                preg_match( "/^RECURRING GLOBALCOLLECT {$this->subscription_id}-2\$/", $contribution['trxn_id'] ) );
        }
    }
}

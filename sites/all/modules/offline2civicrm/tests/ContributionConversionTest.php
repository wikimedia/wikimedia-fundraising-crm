<?php

class ContributionConversionTest extends BaseChecksFileTest {

    public function setUp() {
        parent::setUp();
        civicrm_initialize();
        // I'm slightly confused why this is required. phpunit is blowing away GLOBALS,
        // including the one holding the DB connection but civicrm_initialize is not
        // calling this on the second run due to the static being set.
        // The reason this is confusing is logically, but not in practice,
        // this test should be no more affected than other tests.
        CRM_Core_Config::singleton(TRUE, TRUE);

        $result = $this->callAPISuccess('Contact', 'create', array(
            'contact_type' => 'Individual',
            'email' => 'foo@example.com',
        ));
        $this->contact_id = $result['id'];

        $this->gateway_txn_id = "NaN-" . mt_rand();
        $this->transaction = WmfTransaction::from_unique_id( "GLOBALCOLLECT {$this->gateway_txn_id}" );

        $contributionResult = $this->callAPISuccess('Contribution', 'create', array(
            'contact_id' => $this->contact_id,
            'trxn_id' => $this->transaction->get_unique_id(),
            'contribution_type' => 'Cash',
            'total_amount' => '20.01',
            'receive_date' => wmf_common_date_unix_to_sql( time() ),
        ));
        $this->contribution_id = $contributionResult['id'];

        wmf_civicrm_set_custom_field_values($this->contribution_id, array(
            'original_amount' => '20.01',
            'original_currency' => 'USD',
        ));
    }

    public function tearDown() {
        parent::tearDown();
        $this->callAPISuccess('Contribution', 'delete', array('id' => $this->contribution_id));
        $this->callAPISuccess('Contact', 'delete', array('id' => $this->contact_id));
    }

    public function testMakeRecurring() {
        ContributionConversion::makeRecurring( $this->transaction );

        $contributions = wmf_civicrm_get_contributions_from_gateway_id( $this->transaction->gateway, $this->transaction->gateway_txn_id );
        $this->assertNotNull( $contributions[0]['contribution_recur_id'],
            "Became a recurring contribution" );
    }

    public function testMakeRecurringCancelled() {
        ContributionConversion::makeRecurring( $this->transaction, true );

        $contributions = wmf_civicrm_get_contributions_from_gateway_id( $this->transaction->gateway, $this->transaction->gateway_txn_id );

        $api = civicrm_api_classapi();
        $api->ContributionRecur->Get( array(
            'id' => $contributions[0]['contribution_recur_id'],

            'version' => 3,
        ) );
        $contribution_recur = $api->values[0];
        $this->assertNotNull( $contribution_recur->cancel_date,
            "Marked as cancelled" );
    }
}

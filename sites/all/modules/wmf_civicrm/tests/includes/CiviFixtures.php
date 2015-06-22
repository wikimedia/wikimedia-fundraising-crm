<?php

/**
 * Manage some simple CRM fixtures
 *
 * Instantiate a new one of these for each test suite, and preferrably for each test.
 */
class CiviFixtures {
    // TODO: Clean up interface by grouping each fixture under an object.
    public $contact_group_name;
    public $contact_group_id;
    public $contact_id;
    public $contribution_recur_id;
    public $epoch_time;
    public $org_contact_id;
    public $org_contact_name;
    public $recur_amount;
    public $subscription_id;

    /**
     * @return CiviFixtures
     */
    static function instance() {
        $out = new CiviFixtures();

        $api = civicrm_api_classapi();

        // TODO: clean up the fixtures
        $contact_params = array(
            'contact_type' => 'Individual',
            'first_name' => 'Test',
            'last_name' => 'Es',

            'version' => 3,
        );
        $api->Contact->Create( $contact_params );
        $out->contact_id = $api->id;

        $out->org_contact_name = 'Test DAF ' . mt_rand();
        $contact_params = array(
            'contact_type' => 'Organization',
            'organization_name' => $out->org_contact_name,

            'version' => 3,
        );
        $api->Contact->Create( $contact_params );
        $out->org_contact_id = $api->id;

        $out->recur_amount = '2.34';
        $out->subscription_id = 'SUB-' . mt_rand();
        $out->epoch_time = time();
        $out->sql_time = wmf_common_date_unix_to_sql( $out->epoch_time );

        $initial_contribution_params = array(
            'contact_id' => $out->contact_id,
            'amount' => $out->recur_amount,
            'currency' => 'USD',
            'frequency_unit' => 'month',
            'frequency_interval' => '1',
            'installments' => '0',
            'start_date' => wmf_common_date_unix_to_civicrm( $out->epoch_time ),
            'create_date' => wmf_common_date_unix_to_civicrm( $out->epoch_time ),
            'cancel_date' => null,
            'processor_id' => 1,
            'cycle_day' => '1',
            'next_sched_contribution' => null,
            'trxn_id' => "RECURRING TEST_GATEWAY {$out->subscription_id}-1 {$out->epoch_time}",

            'version' => 3,
        );
        $api->ContributionRecur->Create( $initial_contribution_params );
        $out->contribution_recur_id = $api->id;

        // FIXME: Can't generate random groups because of caching in
        // CRM_Core_Pseudoconstant.  Make temp and random again once we're
        // using Civi 4.6's buildOptions.
        $out->contact_group_name = 'test_thrilled_demographic';
        $success = $api->Group->Get( array(
            'title' => $out->contact_group_name,
            'version' => 3,
        ) );
        if ( $success && $api->values ) {
            $out->contact_group_id = $api->id;
        } else {
            $api->Group->Create( array(
                'title' => $out->contact_group_name,
                'version' => 3,
            ) );
            $out->contact_group_id = $api->id;
        }

        return $out;
    }
}

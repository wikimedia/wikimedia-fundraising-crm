<?php

/**
 * Manage some simple CRM fixtures
 *
 * Instantiate a new one of these for each test suite, and preferrably for each test.
 */
class CiviFixtures {
    // TODO: Clean up interface by grouping each fixture under an object.
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
    static function create() {
        $out = new CiviFixtures();

        $api = civicrm_api_classapi();

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

        $subscription_params = array(
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
        $api->ContributionRecur->Create( $subscription_params );
        $out->contribution_recur_id = $api->id;

        return $out;
    }

    // FIXME: probably need control over destruction order to not conflict with stuff added by test cases
    public function __destruct() {
        $api = civicrm_api_classapi();
        $api->ContributionRecur->Delete( array(
            'id' => $this->contribution_recur_id,
            'version' => 3,
        ) );
        $api->Contact->Delete( array(
            'id' => $this->contact_id,
            'version' => 3,
        ) );
    }
}

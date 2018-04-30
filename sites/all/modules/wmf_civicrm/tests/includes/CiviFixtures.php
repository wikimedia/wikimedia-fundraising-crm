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
    public $contact_hash;
    public $contribution_recur_id;
    public $epoch_time;
    public $org_contact_id;
    public $org_contact_name;
    public $recur_amount;
    public $subscription_id;
    public $contribution_amount;
    public $contribution_invoice_id;
    public $contribution_id;
    public $payment_processor_id;

    /**
     * @return CiviFixtures
     */
    static function create() {
        civicrm_initialize();
        $out = new CiviFixtures();

        static::createContact($out);

        $out->org_contact_name = 'Test DAF ' . mt_rand();

        $organization = civicrm_api3('Contact', 'Create', array(
          'contact_type' => 'Organization',
          'organization_name' => $out->org_contact_name,
        ));
        $out->org_contact_id = $organization['id'];

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
        $contributionRecur = civicrm_api3('ContributionRecur', 'Create', $subscription_params);
        $out->contribution_recur_id = $contributionRecur['id'];

        $out->contact_group_name = 'test_thrilled_demographic';
        $group = civicrm_api3('Group', 'get', array('title' => $out->contact_group_name));

        if ($group['count'] === 1 ) {
            $out->contact_group_id = $group['id'];
        } else {
            $group = civicrm_api3('Group', 'create', array(
              'title' => $out->contact_group_name,
              'name' => $out->contact_group_name,
            ));
            $out->contact_group_id = $group['id'];
        }

        $out->contribution_amount = '1.00';

        $contribution_params = array(
            'contact_id' => $out->contact_id,
            'amount' => $out->contribution_amount,
            'total_amount' => $out->contribution_amount,
            'create_date' => wmf_common_date_unix_to_civicrm( $out->epoch_time ),
            'financial_type_id' => 1,
            'invoice_id' => mt_rand(),
            'skipRecentView' => 1,
        );
        $contribution = civicrm_api3('Contribution', 'Create', $contribution_params);
		$out->contribution_id = $contribution['id'];
        $contribution_values = $contribution['values'][$out->contribution_id];
        $out->contribution_invoice_id = $contribution_values['invoice_id'];

		(new CRM_Core_Transaction())->commit();
        return $out;
    }

    /**
     * @param CiviFixtures|null $instance
     *
     * @return CiviFixtures
     */
    static function createContact($instance = NULL) {
        if ($instance instanceof CiviFixtures) {
            $out = $instance;
        }
        else {
            civicrm_initialize();
            $out = new CiviFixtures();
        }

        $individual = civicrm_api3( 'Contact', 'Create', [
            'contact_type' => 'Individual',
            'first_name' => 'Test',
            'last_name' => 'Es',
        ] );

        $out->contact_id = $individual['id'];
        $out->contact_hash = $individual['values'][$individual['id']]['hash'];
        return $out;
    }

    /**
     * @param $payment_processor payment processor name
     * @param CiviFixtures|null $instance
     *
     * @return CiviFixtures
     */
    static function createPaymentProcessor($payment_processor, $instance = NULL) {
        if ($instance instanceof CiviFixtures) {
            $out = $instance;
        }
        else {
            civicrm_initialize();
            $out = new CiviFixtures();
        }

        $params = [
            'payment_processor_type_id' => 1, //dummy value
            'name' => $payment_processor,
            'domain_id' => CRM_Core_Config::domainID(),
            'is_default' => 1,
            'is_active' => 1,
        ];

        $payment_processor_result = civicrm_api3( 'PaymentProcessor', 'create', $params );
        $out->payment_processor_id = $payment_processor_result['id'];
        return $out;
    }

    /**
     * Tear down function.
     */
    public function __destruct() {
        if ($this->contribution_recur_id) {
            civicrm_api3( 'ContributionRecur', 'delete', ['id' => $this->contribution_recur_id] );
        }
        if ($this->contribution_id) {
            civicrm_api3( 'Contribution', 'delete', ['id' => $this->contribution_id] );
        }
        if ($this->contact_id) {
            civicrm_api3( 'Contact', 'delete', ['id' => $this->contact_id] );
        }
        if ($this->payment_processor_id) {
            civicrm_api3( 'PaymentProcessor', 'delete', ['id' => $this->payment_processor_id] );
        }
    }
}

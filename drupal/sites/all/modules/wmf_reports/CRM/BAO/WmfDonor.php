<?php

class CRM_BAO_WmfDonor {
    static function exportableFields() {
        return array(
            'do_not_solicit' => array(
                'title' => 'Do not solicit',
                'data_type' => CRM_Utils_Type::T_BOOLEAN,
            ),
			'last_donation_date' => array(
                'title' => 'Last donation',
                'data_type' => CRM_Utils_Type::T_DATE,
            ),
            'last_donation_usd' => array(
                'title' => 'Last donation amount (USD)',
                'data_type' => CRM_Utils_Type::T_MONEY,
            ),
            'lifetime_usd_total' => array(
                'title' => 'Lifetime donations (USD)',
                'data_type' => CRM_Utils_Type::T_MONEY,
            ),
        );
    }
}

<?php

class CRM_BAO_SilverpopExport {
    static function exportableFields() {
        return array(
            'latest_donation' => array(
                'title' => 'Last donation',
                'data_type' => CRM_Utils_Type::T_DATE,
            ),
            'latest_usd_amount' => array(
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

<?php

class CRM_BAO_WmfContributionExtra {
    function exportableFields() {
        return array(
            'deposit_date' => array(
                'title' => 'Deposit Date',
                'data_type' => CRM_Utils_Type::T_DATE,
            ),
            'settlement_date' => array(
                'title' => 'Settlement Date',
                'data_type' => CRM_Utils_Type::T_DATE,
            ),
            'original_currency' => array(
                'title' => 'Original Currency',
                'data_type' => CRM_Utils_Type::T_STRING,
            ),
            'gateway' => array(
                'title' => 'Gateway',
                'data_type' => CRM_Utils_Type::T_STRING,
            ),
            'gateway_account' => array(
                'title' => 'Account',
                'data_type' => CRM_Utils_Type::T_STRING,
            ),
        );
    }
}

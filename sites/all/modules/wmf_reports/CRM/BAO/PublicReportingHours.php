<?php

class CRM_BAO_PublicReportingHours {
    function exportableFields() {
        return array(
            'datehour' => array(
                'title' => 'Start of hour',
                'data_type' => CRM_Utils_Type::T_DATE | CRM_Utils_Type::T_TIME,
            ),
            'country' => array(
                'title' => 'Country',
                'data_type' => CRM_Utils_Type::T_STRING,
            ),
            'total' => array(
                'title' => 'Total amount donated',
                'data_type' => CRM_Utils_Type::T_MONEY,
            ),
        );
    }
}

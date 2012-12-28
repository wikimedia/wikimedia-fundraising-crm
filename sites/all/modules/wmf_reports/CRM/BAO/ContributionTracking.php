<?php

class CRM_BAO_ContributionTracking {
    function exportableFields() {
        return array(
            'country' => array(
                'title' => 'Country',
                'data_type' => CRM_Utils_Type::T_STRING,
            ),
        );
    }
}

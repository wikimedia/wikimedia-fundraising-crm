<?php

class CRM_BAO_WmfDonor {
    static function exportableFields() {
        return array(
            'do_not_solicit' => array(
                'title' => 'Do not solicit',
                'data_type' => CRM_Utils_Type::T_BOOLEAN,
            ),
        );
    }
}

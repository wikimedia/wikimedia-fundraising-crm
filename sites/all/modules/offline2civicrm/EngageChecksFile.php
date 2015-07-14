<?php

class EngageChecksFile extends ChecksFile {
    function getRequiredColumns() {
        return array(
            'Batch',
            'Check Number',
            'City',
            'Contribution Type',
            'Country',
            'Direct Mail Appeal',
            'Email',
            'Gift Source',
            'Payment Instrument',
            'Postal Code',
            'Postmark Date',
            'Received Date',
            'Restrictions',
            'Source',
            'State',
            'Street Address',
            'Thank You Letter Date',
            'Total Amount',
        );
    }

    function getRequiredData() {
        return parent::getRequiredData() + array(
            'check_number',
            'gift_source',
            'import_batch_number',
            'payment_method',
            'restrictions',
        );
    }
}

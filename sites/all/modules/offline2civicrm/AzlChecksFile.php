<?php

class AzlChecksFile extends ChecksFile {
    protected $required_fields = array(
        'check_number',
        'date',
        'gift_source',
        'gross',
        'import_batch_number',
        'payment_method',
        'restrictions',
    );

    protected $required_columns = array(
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

    function getRequiredColumns() {
        return $this->required_columns;
    }

    function getRequiredFields() {
        return $this->required_fields;
    }
}

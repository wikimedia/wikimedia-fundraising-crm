<?php

class PayPalChecksFile extends ChecksFile {
    protected $required_fields = array(
        'date',
        'gift_source',
        'gross',
        'payment_method',
        'restrictions',
    );

    protected $required_columns = array(
        'Contribution Type',
        'Received Date',
        'Direct Mail Appeal',
        'First Name',
        'Gift Source',
        'Last Name',
        'No Thank You',
        'Payment Instrument',
        'Restrictions',
        'Source',
        'Total Amount',
    );

    protected function getRequiredColumns() {
        return $this->required_columns;
    }

    protected function getRequiredFields() {
        return $this->required_fields;
    }

    protected function mungeMessage( &$msg ) {
        $msg['gateway'] = 'paypal';
    }
}

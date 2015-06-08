<?php

class PayPalChecksFile extends ChecksFile {

    protected function getRequiredColumns() {
        return array(
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
    }

    protected function getRequiredData() {
        return array(
            'date',
            'gift_source',
            'gross',
            'payment_method',
            'restrictions',
        );
    }

    protected function mungeMessage( &$msg ) {
        $msg['gateway'] = 'paypal';
    }
}

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
        return parent::getRequiredData() + array(
            'gift_source',
            'payment_method',
            'restrictions',
        );
    }

    protected function mungeMessage( &$msg ) {
        $msg['gateway'] = 'paypal';
        $msg['currency'] = 'USD';
    }
}

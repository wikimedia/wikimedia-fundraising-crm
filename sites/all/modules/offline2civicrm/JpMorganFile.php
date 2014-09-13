<?php

class JpMorganFile extends ChecksFile {
    protected function getRequiredColumns() {
        return array(
            'ACCOUNT NAME',
            'CURRENCY',
            'REFERENCE',
            'Bank Ref Number',
            'TRANSACTION DATE',
            'TRANSACTION TYPE',
            'VALUE DATE',
            'CREDITS',
        );
    }

    protected function getRequiredFields() {
        return array(
            'date',
            'gateway_txn_id',
            'gross',
            'original_currency',
            'original_gross',
        );
    }

    protected function getFieldMapping() {
        return array(
            'ACCOUNT NAME' => 'gateway_account',
            'Bank Ref Number' => 'gateway_txn_id',
            'CREDITS' => 'original_gross',
            'CURRENCY' => 'original_currency',
            'TRANSACTION DATE' => 'date',
            'VALUE DATE' => 'settlement_date',
        );
    }

    protected function getDatetimeFields() {
        return array(
            'date',
            'settlement_date',
        );
    }

    protected function getDefaultValues() {
        return array(
            'contact_type' => 'Individual',
            'direct_mail_appeal' => 'White Mail',
            'email' => 'nobody@wikimedia.org',
            'gateway' => 'jpmorgan',
            'gift_source' => 'Community Gift',
            'no_thank_you' => 'No Contact Details',
            'payment_instrument' => 'JP Morgan EUR',
            'restrictions' => 'Unrestricted - General',
        );
    }

    protected function parseRow( $data ) {
        // Empty rows are acceptable for this file
        if ( empty( $data['ACCOUNT NAME'] ) and empty( $data['REFERENCE'] ) ) {
            throw new EmptyRowException();
        }

        return parent::parseRow( $data );
    }

    protected function mungeMessage( &$msg ) {
        // Approximate value in USD
        $msg['gross'] = exchange_rate_convert(
            $msg['original_currency'], $msg['original_gross'], $msg['settlement_date']
        );

        // Flag as big-time if over $1000
        if ( $msg['gross'] > 1000 ) {
            $msg['gift_source'] = 'Benefactor Gift';
        }
    }
}

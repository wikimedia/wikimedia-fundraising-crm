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

    protected function getRequiredData() {
        return parent::getRequiredData() + array(
            'gateway_txn_id',
        );
    }

    protected function getFieldMapping() {
        return array(
            'ACCOUNT NAME' => 'gateway_account',
            'Bank Ref Number' => 'gateway_txn_id',
            'CREDITS' => 'gross',
            'CURRENCY' => 'currency',
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
        return parent::getDefaultValues() + array(
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
}

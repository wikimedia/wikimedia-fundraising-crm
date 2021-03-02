<?php

class JpMorganFile extends ChecksFile {
    protected function getRequiredColumns() {
        return array(
            'Account Name',
            'Currency',
            'Customer Reference',
            'Bank Reference',
            'Transaction Date',
            'Description',
            'Value Date',
            'Credit Amount',
        );
    }

    protected function getRequiredData() {
        return parent::getRequiredData() + array(
            'gateway_txn_id',
        );
    }

    protected function getFieldMapping() {
        return array(
            'Account Name' => 'gateway_account',
            'Bank Reference' => 'gateway_txn_id',
            'Credit Amount' => 'gross',
            'Currency' => 'currency',
            'Transaction Date' => 'date',
            'Value Date' => 'settlement_date',
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

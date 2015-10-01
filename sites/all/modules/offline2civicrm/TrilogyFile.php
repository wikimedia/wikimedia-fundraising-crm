<?php

class TrilogyFile extends ChecksFile {
    protected function getRequiredColumns() {
        return array(
            'Address1',
            'City',
            'contrib_Date',
            'contrib_Account',
            'contrib_Amount',
            'contrib_Batch',
            'contrib_Check',
            'contrib_referenceNumber',
            'Direct Mail Appeal',
            'Email',
            'FirstName',
            'LastName',
            'State',
            'Zip',
        );
    }

protected function getFieldMapping() {
        return array(
            'Address1' => 'street_address',
            'City' => 'city',
            'contrib_Account' => 'raw_contribution_type',
            'contrib_Amount' => 'gross',
            'contrib_Batch' => 'import_batch_number',
            'contrib_Check' => 'payment_submethod',
            'contrib_Date' => 'date',
            'contrib_referenceNumber' => 'gateway_txn_id',
            'Direct Mail Appeal' => 'direct_mail_appeal',
            'Email' => 'email',
            'FirstName' => 'first_name',
            'LastName' => 'last_name',
            'State' => 'state_province',
            'Zip' => 'postal_code',
        );
    }

    protected function getDefaultValues() {
        return parent::getDefaultValues() + array(
            'gateway' => 'trilogy',
            'currency' => 'USD',
            'no_thank_you' => 'trilogy',
            );
    }
}

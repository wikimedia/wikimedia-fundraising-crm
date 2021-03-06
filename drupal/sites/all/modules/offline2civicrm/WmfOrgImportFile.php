<?php

class WmfOrgImportFile extends ChecksFile {
    /** Make sure the file schema is not damaged. */
    protected function getRequiredColumns() {
        return array(
            'Additional Address 1',
            'Additional Address 2',
            'Assistant Contact',
            'Assistant Name',
            'Check Number',
            'City',
            'Contribution Type',
            'Country',
            'Description of Stock',
            'Direct Mail Appeal',
            'Do Not Email',
            'Do Not Mail',
            'Do Not Phone',
            'Do Not SMS',
            'Do Not Solicit',
            'Email',
            'External Batch Number',
            'Gift Source',
            'Groups',
            'Is Opt Out',
            'Name',
            'Notes',
            'No Thank You',
            'Organization Name',
            'Original Amount',
            'Original Currency',
            'Payment Instrument',
            'Phone',
            'Postal Code',
            'Postmark Date',
            'Received Date',
            'Relationship Type',
            'Restrictions',
            'Soft Credit To',
            'State',
            'Street Address',
            'Tags',
            'Target Contact ID',
            'Thank You Letter Date',
            'Title',
            'Transaction ID',
        );
    }
}

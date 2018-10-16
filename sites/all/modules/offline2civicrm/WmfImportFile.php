<?php

class WmfImportFile extends ChecksFile {
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
            'First Name',
            'Gift Source',
            'Groups',
            'Is Opt Out',
            'Last Name',
            'Notes',
            'No Thank You',
            'Original Amount',
            'Original Currency',
            'Phone',
            'Postal Code',
            'Postmark Date',
            'Prefix',
            'Received Date',
            'Relationship Type',
            'Restrictions',
            'Soft Credit To',
            'State',
            'Street Address',
            'Suffix',
            'Tags',
            'Target Contact ID',
            'Thank You Letter Date',
            'Transaction ID',
        );
    }

	protected function validateColumns() {
		if (
			!array_key_exists('Raw Payment Instrument', $this->headers) &&
			!array_key_exists('Payment Instrument', $this->headers)
		) {
			throw new WmfException(
				WmfException::INVALID_FILE_FORMAT,
				'File must contain either \'Payment Instrument\' or \'Raw Payment Instrument\''
			);
		}
		parent::validateColumns();
	}
}

<?php

class ForeignChecksFile extends ChecksFile {
    protected function getRequiredColumns() {
        return array(
            'Check Number',
            'City',
            'Country',
            'Direct Mail Appeal',
            'Do Not Email',
            'Do Not Mail',
            'Do Not Phone',
            'Do Not SMS',
            'Email',
            'First Name',
            'Gift Source',
            'Is Opt Out',
            'Last Name',
            'No Thank You',
            'Notes',
            'Original Amount',
            'Original Currency',
            'Postal Code',
            'Received Date',
            'State',
            'Street Address',
            'Thank You Letter Date',
        );
    }

    protected function getRequiredData() {
        return parent::getRequiredData() + array(
            'check_number',
        );
    }

    protected function mungeMessage( &$msg ) {
        $msg['gateway'] = 'check';

        parent::mungeMessage( $msg );
    }
}

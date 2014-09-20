<?php

class ForeignChecksFile extends ChecksFile {
    function getRequiredColumns() {
        return array(
            'Check Number',
            'City',
            'Country',
            'Direct Mail Appeal',
            'Email',
            'First Name',
            'Gift Source',
            'Last Name',
            'No Thank You',
            'Original Amount',
            'Original Currency',
            'Postal Code',
            'Received Date',
            'State',
            'Street Address',
            'Thank You Letter Date',
        );
    }

    function getRequiredFields() {
        return array(
            'check_number',
            'date',
            'currency',
            'gross',
        );
    }
}

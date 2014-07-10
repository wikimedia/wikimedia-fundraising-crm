<?php

/**
 * Parse Coinbase Merchant Orders report
 *
 * See https://coinbase.com/reports
 */
class CoinbaseFile extends ChecksFile {
    protected $numSkippedRows = 3;

    protected function getRequiredColumns() {
        return array(
            'BTC Price',
            'Currency',
            //'Custom',
            'Customer Email',
            'Native Price',
            'Phone Number',
            'Shipping Address 1',
            'Shipping Address 2',
            'Shipping City',
            'Shipping Country',
            'Shipping Name',
            'Shipping Postal Code',
            'Shipping State',
            'Status',
            'Timestamp',
            'Tracking Code',
        );
    }

    protected function getRequiredFields() {
        return array(
            'date',
            'gross',
            'currency',
            'gateway_txn_id',
            'original_currency',
            'original_gross',
        );
    }

    protected function mungeMessage( &$msg ) {
        $msg['gateway'] = 'coinbase';
        $msg['contribution_type'] = 'cash';
        $msg['payment_instrument'] = 'Bitcoin';

        $msg['original_currency'] = 'BTC';
        $msg['original_gross'] = rtrim( number_format( floatval( $msg['original_gross'] ), 10 ), '0' );

        $msg['first_name'] = $msg['full_name'];

        if ( $msg['gross'] < 0 ) {
            $msg['contribution_type'] = 'refund';
        }
    }

    protected function getFieldMapping() {
        return array(
            'city' => 'Shipping City',
            //'contribution_tracking' => 'Custom', // TODO
            'country' => 'Shipping Country',
            'currency' => 'Currency',
            'date' => 'Timestamp',
            'email' => 'Customer Email',
            'full_name' => 'Shipping Name',
            //'gateway_status_raw' => 'Status', // TODO
            'gateway_txn_id' => 'Tracking Code',
            'gross' => 'Native Price',
            'original_gross' => 'BTC Price',
            'phone' => 'Phone Number', // TODO: not stored
            'postal_code' => 'Shipping Postal Code',
            'state_province' => 'Shipping State',
            'street_address' => 'Shipping Address 1',
            'supplemental_address_1' => 'Shipping Address 2',
        );
    }
}

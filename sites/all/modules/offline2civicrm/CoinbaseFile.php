<?php

/**
 * Parse Coinbase Merchant Orders report
 *
 * See https://coinbase.com/reports
 */
class CoinbaseFile extends ChecksFile {
    protected $numSkippedRows = 2;
    protected $refundLastTransaction = false;

    protected function getRequiredColumns() {
        return array(
            'BTC Price',
            'Currency',
            //'Custom',
            'Customer Email',
            'Native Price',
            'Phone Number',
            'Recurring Payment ID',
            'Refund Transaction ID',
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
        );
    }

    protected function mungeMessage( &$msg ) {
        $msg['gateway'] = 'coinbase';
        $msg['contribution_type'] = 'cash';
        $msg['payment_instrument'] = 'Bitcoin';

        $msg['first_name'] = $msg['full_name'];

        if ( !empty( $msg['gateway_refund_id'] ) ) {
            $this->refundLastTransaction = true;
            unset( $msg['gateway_refund_id'] );
        }

        if ( !empty( $msg['subscr_id'] ) ) {
            $msg['recurring'] = true;
        }
    }

    protected function mungeContribution( $contribution ) {
        if ( $this->refundLastTransaction ) {
            wmf_civicrm_mark_refund(
                $contribution['id'],
                'refund',
                true
            );
            watchdog( 'offline2civicrm', 'Refunding contribution @id', array(
                '@id' => $contribution['id'],
            ), WATCHDOG_INFO );

            $this->refundLastTransaction = false;
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
            'gateway_status_raw' => 'Status',
            'gateway_txn_id' => 'Tracking Code',
            'gateway_refund_id' => 'Refund Transaction ID',
            'gross' => 'Native Price',
            // FIXME: this will destroy recurring subscription import, for now.
            //'original_gross' => 'BTC Price',
            'phone' => 'Phone Number', // TODO: not stored
            'postal_code' => 'Shipping Postal Code',
            'state_province' => 'Shipping State',
            'street_address' => 'Shipping Address 1',
            'subscr_id' => 'Recurring Payment ID',
            'supplemental_address_1' => 'Shipping Address 2',
        );
    }
}

<?php

/**
 * Parse Coinbase Merchant Orders report
 *
 * See https://coinbase.com/reports
 */
class CoinbaseFile extends ChecksFile {
    protected $refundLastTransaction = false;

    protected function getRequiredColumns() {
        return array(
            'BTC Price',
            'Currency',
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

    protected function getRequiredData() {
        return parent::getRequiredData() + array(
            'gateway_txn_id',
        );
    }

    protected function mungeMessage( &$msg ) {
        $msg['gateway'] = 'coinbase';
        $msg['contribution_type'] = 'cash';
        $msg['payment_instrument'] = 'Bitcoin';
        // It seems that payment_method is used for creating the contribution_tracking source
        // whereas payment_instrument is used for CiviCRM contributions payment_instrument_id field.
        $msg['payment_method'] = 'bitcoin';

        list($msg['first_name'], $msg['last_name']) = wmf_civicrm_janky_split_name( $msg['full_name'] );

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
            'Banner' => 'utm_source',
            'Campaign' => 'utm_campaign',
            //'BTC Price' => 'original_gross',
            'Currency' => 'currency',
            'Customer Email' => 'email',
            'Medium' => 'utm_medium',
            // FIXME: this will destroy recurring subscription import, for now.
            'Native Price' => 'gross',
            'Phone Number' => 'phone', // TODO: not stored
            'Recurring Payment ID' => 'subscr_id',
            'Refund Transaction ID' => 'gateway_refund_id',
            'Shipping Address 1' => 'street_address',
            'Shipping Address 2' => 'supplemental_address_1',
            'Shipping City' => 'city',
            'Shipping Country' => 'country',
            'Shipping Name' => 'full_name',
            'Shipping Postal Code' => 'postal_code',
            'Shipping State' => 'state_province',
            'Status' => 'gateway_status_raw',
            'Timestamp' => 'date',
            'Tracking Code' => 'gateway_txn_id',
        );
    }
}
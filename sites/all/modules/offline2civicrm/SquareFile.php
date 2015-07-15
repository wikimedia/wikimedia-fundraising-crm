<?php

class SquareFile extends ChecksFile {
    protected $refundLastTransaction = false;

    protected function getRequiredColumns() {
        return array(
            'Currency',
            'Email Address',
            'Gross Amount',
            'Name',
            'Net Amount',
            'Payment ID',
            'Phone Number',
            'Status',
            'Timestamp',
            'Zip Code',
        );
    }

    protected function getRequiredData() {
        return parent::getRequiredData() + array(
            'gateway_txn_id',
        );
    }

    protected function getFieldMapping() {
        return array(
            'Currency' => 'currency',
            'Email Address' => 'email',
            'Gross Amount' => 'gross',
            'Name' => 'full_name',
            'Net Amount' => 'net',
            'Payment ID' => 'gateway_txn_id',
            'Phone Number' => 'phone',
            'Status' => 'gateway_status_raw',
            'Timestamp' => 'date',
            'Zip Code' => 'postal_code',
        );
    }

    protected function parseRow ( $data ) {
        // completed and refunded are the only rows that mean anything.
        // the others as of now are pending, canceled, and deposited.
        if (! in_array($data['Status'], array('Completed', 'Refunded'))) {
            throw new IgnoredRowException;
        }

        return parent::parseRow( $data );
    }

    protected function mungeMessage ( &$msg ) {
        $msg['gateway'] = 'square';
        $msg['contribution_type'] = 'cash';

        $msg['gross'] = ltrim( $msg['gross'], '$' );
        $msg['gross'] = preg_replace( '/,/', '', $msg['gross'] );

        if ( array_key_exists('net', $msg) ) {
            $msg['net'] = ltrim( $msg['net'], '$' );
            $msg['net'] = preg_replace( '/,/', '', $msg['net'] );
        }

        list($msg['first_name'], $msg['last_name']) = wmf_civicrm_janky_split_name( $msg['full_name'] );

        if ( $msg['gateway_status_raw'] === 'Refunded' ) {
            $msg['net'] = $msg['gross']; // in refunds net is set to zero for some reason
            $this->refundLastTransaction = true;
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

    protected function handleDuplicate( $duplicate ) {
        if ( $this->refundLastTransaction ) {
            // override the default behavior which is skip all duplicates.
            // square sends refund rows with the same transaction ID as
            // the parent contribution.  so in this case we still want to
            // ignore the sent row but also insert a wmf approved refund.
            try {
                wmf_civicrm_mark_refund(
                    $duplicate[0]['id'],
                    'refund',
                    true
                );
            } catch ( WmfException $ex ) {
                // TODO DuplicateRowException?
                if ( $ex->getCode() === WmfException::DUPLICATE_CONTRIBUTION ) {
                    return true; // duplicate refund
                } else {
                    throw $ex;
                }
            }

            watchdog( 'offline2civicrm', 'Refunding contribution @id', array(
                '@id' => $duplicate[0]['id'],
            ), WATCHDOG_INFO );

            $this->refundLastTransaction = false;
            return false; // false means this was a refund not a duplicate
        }

        return true;
    }

}

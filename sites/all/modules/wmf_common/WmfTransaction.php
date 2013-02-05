<?php
// namespace wmf_common;

/**
 * Contain assumptions about our transactions.
 *
 * Data is lazy-loaded, so an object of this type is efficient to use as a
 * temporary helper variable.
 * 
 * For example,
 *   $trxn_id = WmfTransaction::parse( $msg )->get_unique_id();
 *
 * This wraps our unique ID generator / parser, and...
 */
class WmfTransaction {
    var $gateway;
    var $gateway_txn_id;
    var $timestamp;
    var $is_refund;
    var $is_recurring;
    var $recur_sequence;

    //FIXME: we can't actually cache without wrapping set accessors
    //var $unique_id;

    static function parse( $mixed ) {
        // must be a unique-ish id
        if ( is_string( $mixed ) or is_numeric( $mixed ) ) {
            $transaction = static::from_unique_id( (string) $mixed );
        }
        // civi contribution
        elseif ( is_object( $mixed ) and property_exists( $mixed, 'trxn_id' ) ) {
            $transaction = static::from_unique_id( $mixed->trxn_id );
        }
        elseif ( is_array( $mixed ) and array_key_exists( 'trxn_id', $mixed ) ) {
            $transaction = static::from_unique_id( $mixed['trxn_id'] );
        }
        // stomp message, does not have a unique id yet
        elseif ( is_array( $mixed ) and array_key_exists( 'gateway_txn_id', $mixed ) ) {
            $transaction = new WmfTransaction();
            $transaction->gateway_txn_id = $mixed['gateway_txn_id'];
            $transaction->gateway = $mixed['gateway'];
            if ( array_key_exists( 'recurring', $mixed ) && $mixed['recurring'] ) {
                $transaction->is_recurring = true;
            }
            if ( strcasecmp( $transaction->gateway, $mixed['gateway'] ) ) {
                throw new Exception( "Malformed unique id (msg but gateway does not match)" );
            }
        } else {
            throw new Exception( "Unknown input type: " . print_r( $mixed, true ) );
        }
        return $transaction;
    }

    function get_unique_id() {
        $parts = array();

        if ( $this->is_refund ) {
            $parts[] = "RFD";
        }

        if ( $this->is_recurring ) {
            $parts[] = "RECURRING";
        }

        //FIXME: validate that a gateway has been set.
        $parts[] = $this->gateway;

        $txn_id = $this->gateway_txn_id;
        if ( $this->recur_sequence ) {
            // TODO push this down into GC recur
            $txn_id .= "-" . $this->recur_sequence;
        }
        $parts[] = $txn_id;

        // FIXME: deprecate the timestamp term
        if ( !$this->timestamp ) {
            $this->timestamp = time();
        }
        $parts[] = $this->timestamp;

        return strtoupper( implode( " ", $parts ) );
    }

    static function from_unique_id( $unique_id ) {
        $transaction = new WmfTransaction();

        $parts = explode( ' ', $unique_id );

        if ( count( $parts ) === 0 ) {
            throw new Exception( "Missing ID." );
        }

        while ( $parts[0] === "RFD" or $parts[0] === "REFUND" ) {
            $transaction->is_refund = true;
            array_shift( $parts );
        }

        while ( $parts[0] === "RECURRING" ) {
            $transaction->is_recurring = true;
            array_shift( $parts );
        }

        switch ( count( $parts ) ) {
        case 0:
            throw new Exception( "Unique ID is missing terms." );
        case 3:
            $transaction->timestamp = array_pop( $parts );
            if ( !is_numeric( $transaction->timestamp ) ) {
                throw new Exception( "Malformed unique id (timestamp does not appear to be numeric)" );
            }
            // pass
        case 2:
            $transaction->gateway = strtolower( array_shift( $parts ) );
            // pass
        case 1:
            $transaction->gateway_txn_id = array_shift( $parts );
            break;
        default:
            throw new Exception( "Malformed unique id (too many terms)" );
        }

        if ( !$transaction->timestamp ) {
            $transaction->timestamp = time();
        }

        // TODO: debate whether to renormalize here
        $transaction->unique_id = $transaction->get_unique_id();

        return $transaction;
    }
}

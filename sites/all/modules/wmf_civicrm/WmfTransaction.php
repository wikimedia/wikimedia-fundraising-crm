<?php
// namespace wmf_common;

/**
 * Contain assumptions about our transactions.
 *
 * Data is lazy-loaded, so an object of this type is efficient to use as a
 * temporary helper variable.
 * 
 * For example,
 *   $trxn_id = WmfTransaction::from_message( $msg )->get_unique_id();
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

    static function from_message( $msg ) {
        // stomp message, does not have a unique id yet
        $transaction = new WmfTransaction();
        $transaction->gateway_txn_id = $msg['gateway_txn_id'];
        $transaction->gateway = $msg['gateway'];
        if ( array_key_exists( 'recurring', $msg ) && $msg['recurring'] ) {
            $transaction->is_recurring = true;
        }
        return $transaction;
    }

    static function from_unique_id( $unique_id ) {
        $transaction = new WmfTransaction();

        $parts = explode( ' ', $unique_id );

        if ( count( $parts ) === 0 ) {
            throw new WmfException( 'INVALID_MESSAGE', "Missing ID." );
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
            throw new WmfException( 'INVALID_MESSAGE', "Unique ID is missing terms." );
        case 3:
            $transaction->timestamp = array_pop( $parts );
            if ( !is_numeric( $transaction->timestamp ) ) {
                throw new WmfException( 'INVALID_MESSAGE', "Malformed unique id (timestamp does not appear to be numeric)" );
            }
            // pass
        case 2:
            $transaction->gateway = strtolower( array_shift( $parts ) );
            // pass
        case 1:
            $transaction->gateway_txn_id = array_shift( $parts );
            break;
        default:
            throw new WmfException( 'INVALID_MESSAGE', "Malformed unique id (too many terms)" );
        }

        if ( !$transaction->timestamp ) {
            $transaction->timestamp = time();
        }

        // TODO: debate whether to renormalize here
        $transaction->unique_id = $transaction->get_unique_id();

        return $transaction;
    }

    function exists() {
        try {
            $this->getContribution();
            return true;
        } catch ( WmfException $ex ) {
            return false;
        }
    }

    /**
     * @return array of civicrm_contribution and wmf_contribution_extra db values
     */
    function getContribution() {
        $contributions = wmf_civicrm_get_contributions_from_gateway_id( $this->gateway, $this->gateway_txn_id );
        if ( !$contributions ) {
            throw new NoTransactionExists( $this );
        } elseif ( count( $contributions ) > 1 ) {
            throw new NonUniqueTransaction( $this );
        } else {
            return array_shift( $contributions );
        }
    }
}

class NoTransactionExists extends WmfException {
    function __construct( WmfTransaction $transaction ) {
        parent::__construct( "GET_CONTRIBUTION", "No such transaction: {$transaction->get_unique_id()}" );
    }
}

class NonUniqueTransaction extends WmfException {
    function __construct( WmfTransaction $transaction ) {
        parent::__construct( "GET_CONTRIBUTION", "Transaction does not resolve to a single contribution: {$transaction->get_unique_id()}" );
    }
}

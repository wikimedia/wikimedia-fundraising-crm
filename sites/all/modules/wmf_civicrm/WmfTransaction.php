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
    var $is_refund;
    var $is_recurring;

    /** @deprecated */
    var $timestamp;

    //FIXME: we can't actually lazy evaluate without wrapping set accessors
    //var $unique_id;

    function get_unique_id() {
        $parts = array();

        if ( $this->is_refund ) {
            $parts[] = "RFD";
        }

        if ( $this->is_recurring ) {
            $parts[] = "RECURRING";
        }

        if ( !$this->gateway ) {
            throw new WmfException( 'INVALID_MESSAGE', 'Missing gateway.' );
        }
        if ( !$this->gateway_txn_id ) {
            throw new WmfException( 'INVALID_MESSAGE', 'Missing gateway_txn_id.' );
        }
        $parts[] = $this->gateway;
        $parts[] = $this->gateway_txn_id;

        return strtoupper( implode( " ", $parts ) );
    }

    static function from_message( $msg ) {
        // stomp message, does not have a unique id yet
        $transaction = new WmfTransaction();
        if ( isset( $msg['gateway_refund_id'] ) ) {
            $transaction->gateway_txn_id = $msg['gateway_refund_id'];
        } else {
            $transaction->gateway_txn_id = $msg['gateway_txn_id'];
        }
        $transaction->gateway = $msg['gateway'];
        $transaction->is_recurring = !empty( $msg['recurring'] );
        return $transaction;
    }

    static function from_unique_id( $unique_id ) {
        $transaction = new WmfTransaction();

        $parts = explode( ' ', trim( $unique_id ) );

        $transaction->is_refund = false;
        while ( $parts and in_array( $parts[0], array( 'RFD', 'REFUND' ) ) ) {
            $transaction->is_refund = true;
            array_shift( $parts );
        }

        $transaction->is_recurring = false;
        while ( $parts and $parts[0] === 'RECURRING' ) {
            $transaction->is_recurring = true;
            array_shift( $parts );
        }

        switch ( count( $parts ) ) {
        case 0:
            throw new WmfException( 'INVALID_MESSAGE', "Unique ID is missing terms." );
        case 3:
            // TODO: deprecate timestamp
            $transaction->timestamp = array_pop( $parts );
            if ( !is_numeric( $transaction->timestamp ) ) {
                throw new WmfException( 'INVALID_MESSAGE', "Malformed unique id (timestamp does not appear to be numeric)" );
            }
            // pass
        case 2:
            $transaction->gateway = strtolower( array_shift( $parts ) );
            // pass
        case 1:
            // Note that this sucks in effort_id and any other stuff we're
            // using to maintain an actually-unique per-gateway natural key.
            $transaction->gateway_txn_id = array_shift( $parts );
            if ( empty( $transaction->gateway_txn_id ) ) {
                throw new WmfException( 'INVALID_MESSAGE', "Empty gateway transaction id" );
            }
            break;
        default:
            throw new WmfException( 'INVALID_MESSAGE', "Malformed unique id (too many terms)" );
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

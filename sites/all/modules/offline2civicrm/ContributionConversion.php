<?php

class ContributionConversion {
    static function makeRecurring( WmfTransaction $transaction, $cancel = false ) {
        $contribution = WmfTransaction::from_unique_id( "{$transaction->gateway} {$transaction->gateway_txn_id}" )->getContribution();
        if ( $contribution['contribution_recur_id'] ) {
            throw new AlreadyRecurring( $transaction );
        }

        $transaction->is_recurring = true;
        $contribution['trxn_id'] = $transaction->get_unique_id();
        $synth_msg = array(
            'recurring' => 1,
            'original_gross' => $contribution['original_amount'],
            'original_currency' => $contribution['original_currency'],
            'date' => wmf_common_date_civicrm_to_unix( $contribution['receive_date'] ),
            'cancel' => $cancel,
        );
        wmf_civicrm_message_contribution_recur_insert( $synth_msg, $contribution['contact_id'], $contribution );
        $dbs = wmf_civicrm_get_dbs();
        $dbs->push( 'civicrm' );
        $result = db_update( 'civicrm_contribution' )->fields( array(
            'trxn_id' => $contribution['trxn_id'],
        ) )->condition( 'id', $contribution['id'] )->execute();
    }
}

class AlreadyRecurring extends WmfException {
    function __construct( WmfTransaction $transaction ) {
        parent::__construct( "DUPLICATE_CONTRIBUTION", "Already a recurring contribution: {$transaction->get_unique_id()}" );
    }
}

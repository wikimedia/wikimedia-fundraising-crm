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
        // FIXME: preserving old incorrect behavior.  Use a real subscr_id here.
        wmf_civicrm_message_contribution_recur_insert( $synth_msg, $contribution['contact_id'], $contribution['trxn_id'], $contribution );
        civicrm_api3('Contribution', 'Create', array(
          'id' => $contribution['id'],
          'trxn_id' => $contribution['trxn_id'],
        ));
    }
}

class AlreadyRecurring extends WmfException {
    function __construct( WmfTransaction $transaction ) {
        parent::__construct( "DUPLICATE_CONTRIBUTION", "Already a recurring contribution: {$transaction->get_unique_id()}" );
    }
}

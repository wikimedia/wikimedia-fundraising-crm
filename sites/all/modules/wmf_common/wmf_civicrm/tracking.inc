<?php

/**
 * Insert a record into contribution_tracking table
 * 
 * Primarily used when a record does not already exist in the table for a
 * particular transaction.  Rare, but inserting some data for a trxn when
 * absent helps facilitate better analytics.
 * 
 * @return the contribution_tracking id 
 */
function wmf_civicrm_insert_contribution_tracking( $utm_source, $utm_medium, $ts, $contrib_id=null, $optout=null, $anonymous=null ) {
    // make sure we're using the default (drupal) db
    $dbs = wmf_civicrm_get_dbs();
    $dbs->push( 'donations' );

    if ($contrib_id === null){
        $query = "INSERT INTO {contribution_tracking} ( utm_source, utm_medium, optout, anonymous, ts ) VALUES ( '%s', '%s', '%d', '%d', '%d' )";
    } else {
        $query = "INSERT INTO {contribution_tracking} ( utm_source, utm_medium, optout, anonymous, ts, contribution_id ) VALUES ( '%s', '%s', '%d', '%d', '%d', '%d' )";
    }
    db_query( $query, $utm_source, $utm_medium, $optout, $anonymous, $ts, $contrib_id );
    return db_last_insert_id( 'contribution_tracking', 'id' );
}

/**
 * Map contribution to tracking record in contribution_tracking table
 * 
 * @param array $msg
 * @param array $contribution
 */
function wmf_civicrm_message_update_contribution_tracking( $msg, $contribution ) {
    $dbs = wmf_civicrm_get_dbs();
    $dbs->push( 'donations' );

    if (array_key_exists( 'contribution_tracking_id', $msg )) {
        $result = db_query('UPDATE {contribution_tracking} SET contribution_id=%d WHERE id=%d', $contribution['id'], $msg['contribution_tracking_id']);
        if ( !$result ) {
            watchdog( 'wmf_civicrm', "There was a problem updating contribution_tracking for message: %msg", array( '%msg' => print_r( $msg, true )), WATCHDOG_ERROR );
            return FALSE;
        } else {
            watchdog( 'wmf_civicrm', 'Successfully updated contribution_tracking for message: %msg', array( '%msg' => print_r( $msg, true)));
            return TRUE;
        }
    }
}

<?php

/**
 * Insert a record into contribution_tracking table
 *
 * Primarily used when a record does not already exist in the table for a
 * particular transaction.  Rare, but inserting some data for a trxn when
 * absent helps facilitate better analytics.
 *
 * @param array $values associative array of columns => values to insert
 *  into the contribution tracking table
 * @return int the contribution_tracking id
 */
function wmf_civicrm_insert_contribution_tracking( $values ) {
    // make sure we're using the default (drupal) db
    $dbs = wmf_civicrm_get_dbs();
    $dbs->push( 'default' );

    $contribution_tracking_id = db_insert( 'contribution_tracking' )->fields( $values )->execute();
    watchdog( 'wmf_civicrm', "Created new contribution_tracking entry %id", array( '%id' => $contribution_tracking_id ), WATCHDOG_INFO );

    return $contribution_tracking_id;
}

/**
 * Update contribution_tracking record with a pointer to the contribution.
 * 
 * @param array $msg
 * @param array $contribution
 */
function wmf_civicrm_message_update_contribution_tracking( $msg, $contribution ) {
    $dbs = wmf_civicrm_get_dbs();
    $dbs->push( 'default' );

    if (array_key_exists( 'contribution_tracking_id', $msg )) {
        $result = db_update( 'contribution_tracking' )->fields( array(
            'contribution_id' => $contribution['id'],
        ) )->condition( 'id', $msg['contribution_tracking_id'] )->execute();
        if ( !$result ) {
            watchdog( 'wmf_civicrm', "There was a problem updating contribution_tracking for %id", array( '%id' => $msg['contribution_tracking_id'] ), WATCHDOG_ERROR );
            return FALSE;
        } else {
            watchdog( 'wmf_civicrm', 'Successfully updated contribution_tracking for %id', array( '%id' => $msg['contribution_tracking_id'] ), WATCHDOG_INFO );
            return TRUE;
        }
    }
}

function wmf_civicrm_get_contribution_tracking( $msg ) {
    if ( array_key_exists( 'contribution_tracking_id', $msg ) ) {
        return db_select( 'contribution_tracking', 'contribution_tracking' )
            ->fields( 'contribution_tracking' )
            ->condition( 'id', $msg['contribution_tracking_id'] )
            ->execute()
            ->fetchAssoc();
    } else {
        return FALSE;
    }
}

<?php

/**
* Implementation of hook_drush_command()
*/
function resettle_drush_command() {
    $items = array();

    $items['resettle'] = array(
        'description' => 'Recalculate settlement amounts',
        'options' => array(
            'batch' => "Batch size",
        ),
    );

    return $items;
}

/**
 * Implementation of hook_drush_help()
 */
function resettle_drush_help( $section ) {
    switch ( $section ) {
        case 'drush:resettle':
            return dt( "re-settle transactions in different currencies, based on the date the transaction was settled, and the settlement currency of that gateway account" );
    }
}

function drush_resettle() {
    module_invoke( 'civicrm', 'initialize' );

    $batch_size = (integer) drush_get_option( 'batch', variable_get( 'wmf_civicrm_resettle_batch_size', 1 ) );
    $data = array(
    );

    foreach ( $data as $record ) {
        if ( $batch_size <= 0 ) {
            break;
        }
        $record = array_shift( $data );
        $contribution = $record['contribution'];
        $settled_timestamp = $record['settled_time'];
        $contribution = module_invoke( 'wmf_civicrm', 'settle', $contribution, $settled_timestamp );

        $batch_size--;
    }

    drush_print( 'Psyke!  That did nothing.' );
}

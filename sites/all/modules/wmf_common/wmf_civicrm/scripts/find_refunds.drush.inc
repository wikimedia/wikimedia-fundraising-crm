<?php

/**
* Implementation of hook_drush_command()
*/
function find_refunds_drush_command() {
    $items = array();

    $items['find_refunds'] = array(
        'description' => 'Find transactions which should be marked as a refund or chargeback',
    );

    return $items;
}

/**
 * Implementation of hook_drush_help()
 */
function find_refunds_drush_help( $section ) {
    switch ( $section ) {
    case 'drush:find_refunds':
        return dt( 'Find transactions which should be marked as a refund or chargeback, but were zeroed-out in the old style.' );
    }
}

function drush_find_refunds() {
    module_invoke( 'civicrm', 'initialize' );
    $dbs = module_invoke( 'wmf_civicrm', 'get_dbs' );
    $dbs->push( 'civicrm' );

    $sql = <<<EOS
SELECT id, trxn_id FROM civicrm_contribution
WHERE
    total_amount = 0
    OR source LIKE 'RFD%'
ORDER BY trxn_id
EOS;
    $result = db_query( $sql );

    $count = 0;
    while ( $row = db_fetch_array( $result ) ) {
        drush_print( "{$row['id']},{$row['trxn_id']}" );
        $count++;
    }
}

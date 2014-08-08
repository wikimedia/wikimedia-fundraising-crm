<?php

function recurring_globalcollect_patch_history_drush_command() {
    $items['recurring-globalcollect-patch-history'] = array(
        'description' => 'Create contribution entries for recurring donations that were charged, but not recorded.',

        'arguments' => array(
            'glitch_day' => 'All donations were received on this day.  Use MySQL date format, like: "20140508" for May 8, 2014',
            'affected_orders_file' => 'Path to a CSV file containing the charges that were not recorded.  Stupid format is: order_id,effort_id.',
        ),
        'options' => array(
            'contribution_tag' => 'Annotation to add to the contribution record, for example: --contribution_tag UnrecordedCharge',
        ),
    );
    return $items;
}

function drush_recurring_globalcollect_patch_history( $glitch_day, $affected_orders_file ) {
    watchdog( 'recurring_globalcollect', "Beginning patchup job, date of incident: {$glitch_day}, reading order IDs from {$affected_orders_file}", NULL, WATCHDOG_INFO );
    civicrm_initialize();

    $tags = array();
    $contribution_tag = drush_get_option('contribution_tag');
    if ( $contribution_tag ) {
        $tags[] = $contribution_tag;
    }

    $f = fopen( $affected_orders_file, "r" );
    while ( $row = fgetcsv( $f ) ) {
        recurring_globalcollect_create_historical_donation( $glitch_day, $row[0], $row[1], $tags );
    }
    fclose( $f );

    watchdog( 'recurring_globalcollect', 'Finished patchup job.', NULL, WATCHDOG_INFO );
}

function recurring_globalcollect_create_historical_donation( $date, $order_id, $effort_id, $tags ) {
    $recur_record = _recurring_globalcollect_get_subscription_by_order_id( $order_id );

    if ( !$recur_record ) {
        throw new Exception( "Subscription not found: $order_id" );
    }
    $msg = recurring_globalcollect_create_message( $recur_record['id'] );

    $msg['date'] = strtotime( $date );
    $msg['source_type'] = 'direct';
    $msg['source_name'] = 'Recurring Globalcollect missing record';

    // Ruthless.  Note that this script is not idempotent.
    $transaction = WmfTransaction::from_unique_id( $recur_record['trxn_id'] );
    $msg['effort_id'] = $effort_id;
    $msg['gateway_txn_id'] = "{$transaction->gateway_txn_id}-{$effort_id}";

    $msg['contribution_tags'] = $tags;

    watchdog( 'recurring_globalcollect', 'Importing payment record: ' . print_r( $msg ), NULL, WATCHDOG_INFO );
    $contribution = wmf_civicrm_contribution_message_import( $msg );
    watchdog( 'recurring_globalcollect', 'Created contribution: ' . print_r( $contribution ), NULL, WATCHDOG_INFO );
}

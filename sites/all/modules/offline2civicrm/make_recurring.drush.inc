<?php
/**
 * Implementation of hook_drush_command()
 */
function make_recurring_drush_command() {
    $items = array();

    $items['make-recurring'] = array(
        'description' =>
            'Convert non-recurring contributions into recurring payments.',
        'examples' => array(
            'Batch conversion' => 'drush make-recurring dumpsfile.csv',
            # FIXME: need such a thing?
            # 'Convert single transaction by ID' => 'drush make-recurring --gateway globalcollect --gateway_txn_id 1232323',
        ),
        'required-arguments' => true,
        'arguments' => array(
            'file' => 'Name of CSV file to process',
        ),
    );

    return $items;
}

/**
 * Loads lines from a file and imports into CiviCRM
 */
function drush_make_recurring() {
    $args = drush_get_arguments();

    /* FIXME: abandoned code
    if ( count( $args ) > 2 ) {
        // by id
        $transaction = WmfTransaction::from_unique_id( "{$args[1]} {$args[2]}" );
        ContributionConversion::makeRecurring( $transaction );
        return;
    */

    $filename = $args[1];

    $skipped = 0;
    $processed = 0;
    $batchfile = new CsvBatchFile( $filename );
    while ( $row = $batchfile->read_line() ) {
        try {
            $transaction = WmfTransaction::from_unique_id( "{$row['gateway']} {$row['gateway_txn_id']}" );
            ContributionConversion::makeRecurring( $transaction );
            $processed++;
        }
        catch ( AlreadyRecurring $ex ) {
            // FIXME: sort of a lucky accident that we will catch this case,
            // for now.  WmfTransaction::getContribution should actually filter
            // by recurring.
            watchdog( 'offline2civicrm', $ex->getMessage(), NULL, WATCHDOG_DEBUG );
            $skipped++;
        }
        catch ( NoTransactionExists $ex ) {
            watchdog( 'offline2civicrm', $ex->getMessage(), NULL, WATCHDOG_DEBUG );
            $skipped++;
        }
    }
    watchdog( 'offline2civicrm', "Processed {$processed} contributions, skipped {$skipped}.", NULL, WATCHDOG_INFO );
}

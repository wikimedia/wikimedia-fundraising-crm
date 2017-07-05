<?php
/**
 * @file fredge_queue_consume.drush.inc
 *
 * Pops intresting fundraising metric data off of whatever queue and stuffs it
 * into the fredge database for later analysis.
 *
 * @author Katie Horn <khorn@wikimedia.org>
 */

/**
 * Implementation of hook_drush_command()
 */
function fredge_queue_consume_drush_command() {
	$items = array();

	$items['fredge-queue-consume'] = array(
		'description' => 'Populates the fredge database with messages it pops off of one of several queues',
    'examples' => array(
			'drush fredge-queue-consume' => 'Shovels messages straight into the database.',
    ),
		'aliases' => array( 'fqc' ),
	);
    $items['fredge-process-messagefile'] = array(
        'description' => 
            'Feeds a single message directly into the import pipeline, bypassing the queue.',
        'arguments' => array(
            'type' => 'Either "init" or "fraud"',
            'path' => 'The JSON file containing the message',
        ),
        'required-arguments' => true,
        'examples' => array( 'drush fpm init /tmp/blurr.json' => '# process the file' ), 
        'aliases' => array( 'fpm' ),
    );
	return $items;
}

/**
 * Implementation of hook_drush_help()
 *
 * @param $section
 * @return mixed
 */
function fredge_queue_consume_drush_help( $section ) {
	switch ( $section ) {
		case 'drush:fredge-queue-consume':
			return dt( "Pulls fredge requests from a remote queue; then uses the Civi API to opt them out.");
	}
}

/**
 * Generates translated versions of the fredge pages from the
 * DonationInterface i18n files.
 */
function drush_fredge_queue_consume() {
	watchdog('fredge', 'Executing: drush_fredge_queue_consume');
	module_invoke( 'fredge', 'batch_process' );
	$errors = drush_get_error_log();
	if (!empty($errors)){
		echo "\n***ERRORS***";
		foreach($errors as $error=>$msgarray){
			echo "\n$error: ";
			foreach ($msgarray as $count=>$message){
				echo "\n	  $message";
			}
		}
		echo "\n\n";
		exit(drush_get_error());
	}
}

// FIXME: this is now broken
function drush_fredge_queue_consume_fredge_process_messagefile( $type, $path ) {
    watchdog( 'queue2civicrm', "Processing input file @path and feeding to queue2civicrm_import.",
        array( '@path' => realpath( $path ) ), WATCHDOG_INFO );
    $contents = file_get_contents( $path );

    $msg = new stdClass();
    $msg->headers = array(
        'correlation-id' => mt_rand(),
    );
    $msg->body = $contents;

    switch ( $type ) {
    case 'init':
        module_invoke( 'fredge', 'payments_init_process_message', $msg );
        break;
    case 'fraud':
        module_invoke( 'fredge', 'payments_antifraud_process_message', $msg );
        break;
    default:
        throw new Exception( "Unsupported queue type: $type" );
    }
}

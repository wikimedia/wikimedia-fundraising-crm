<?php
/**
 * @file banner_history.drush.inc
 *
 * Consumes associations of banner history log IDs and contribution tracking IDs
 * from a remote queue, then stores them in a table.
 *
 * @author Andrew Green <agreen@wikimedia.org>
 */

/**
 * Implementation of hook_drush_command()
 */
function banner_history_queue_consume_drush_command() {
	$items = array();

	$items['banner-history-queue-consume'] = array(
		'description' => 'Consume associations of banner history log IDs and ' .
			'contribution tracking IDs.',
		'aliases' => array( 'bhqc' ),
	);
	return $items;
}

/**
 * Implementation of hook_drush_help()
 * @param $section
 * @return mixed
 */
function banner_history_queue_consume_drush_help( $section ) {
	switch ( $section ) {
		case 'drush:banner-history-queue-consume':
			return dt( "Consume associations of banner history log IDs and " .
				"contribution tracking IDs from a remote queue, then store " .
				"them in a table." );
	}
}

/**
 * Implements the drush comand banner-history-queue consume; consumes
 * associations of banner history log IDs and contribution tracking IDs and puts
 * them in a table.
 */
function drush_banner_history_queue_consume() {

	watchdog( 'banner_history', 'Executing: drush_banner_history_queue_consume' );
	module_invoke( 'banner_history', 'queue_consume' );

	// TODO Copypasta from error handling in unsubscribe_queue_consume.drush.inc
	// Should be consolidated somewhere

	$errors = drush_get_error_log();

	if ( !empty( $errors ) ){

		echo "\n***ERRORS***";

		foreach( $errors as $error => $msgarray ){
			echo "\n$error: ";
			foreach ( $msgarray as $count => $message ){
				echo "\n\t$message";
			}
		}

		echo "\n\n";
		exit( drush_get_error() );
	}
}
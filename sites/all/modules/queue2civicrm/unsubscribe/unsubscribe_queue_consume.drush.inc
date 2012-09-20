<?php
/**
 * @file unsub-qc.drush.inc
 *
 * Pulls unsubscribe requests from a remote queue; then uses the Civi API to opt them out.
 *
 * @author Matt Walker <mwalker@wikimedia.org>
 */

/**
 * Implementation of hook_drush_command()
 */
function unsubscribe_queue_consume_drush_command() {
	$items = array();

	$items['unsubscribe-queue-consume'] = array(
		'description' => 'Pulls unsubscribe requests from a remote queue; then uses the Civi API to opt them out.',
		'examples' => array(
			'drush unsubqc' => 'Pulls a default number of messages and unsubscribes',
		),
		'aliases' => array( 'unsubqc' ),
	);
	return $items;
}

/**
 * Implementation of hook_drush_help()
 *
 * @param $section
 * @return mixed
 */
function unsubscribe_queue_consume_drush_help( $section ) {
	switch ( $section ) {
		case 'drush:unsubscribe-queue-consume':
			return dt( "Pulls unsubscribe requests from a remote queue; then uses the Civi API to opt them out.");
	}
}

/**
 * Generates translated versions of the unsubscribe pages from the
 * DonationInterface i18n files.
 */
function drush_unsubscribe_queue_consume() {
	watchdog('unsubscribe', 'Executing: drush_unsubscribe_queue_consume');
	module_invoke( 'unsubscribe', 'batch_process' );
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


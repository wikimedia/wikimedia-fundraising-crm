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


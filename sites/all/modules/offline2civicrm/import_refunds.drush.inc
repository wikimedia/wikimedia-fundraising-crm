<?php

/**
 * @file import_refunds.drush.inc
 *
 * Import refunds from csv into CiviCRM
 *
 * @author Elliott Eggleston <eeggleston@wikimedia.org>
 */

/**
 * Implementation of hook_drush_command()
 */
function import_refunds_drush_command() {
	$items = array();

	$items['import-refunds'] = array(
		'description' => 'Import refunds into CiviCRM from a csv',
		'examples' => array( 'drush import-refunds globalcollect filename.csv' ),
		'required-arguments' => true,
		'arguments' => array(
			'processor' => 'Name of payment processor',
			'file' => 'Name of csv file to process'
		),
	);

	return $items;
}

/**
 * Implementation of hook_drush_help()
 */
function import_refunds_drush_help( $section ) {
	switch ( $section ) {
		case 'drush:import-refunds':
			return dt( "Import refunds into CiviCRM from a csv" );
	}
}

function drush_import_refunds( $processor = null, $filename = null ) {

	try{
		$importer = new RefundFile( $processor, $filename );
		$importer->import();
	} catch ( Exception $e ) {
		watchdog( 'offline2civicrm', 'Import refunds: Exception thrown during refund processing: ' .
			print_r( $e, true ), array(), WATCHDOG_ERROR );
	}
}

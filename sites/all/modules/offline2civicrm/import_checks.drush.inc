<?php
/**
 * @file check_import.drush.inc
 *
 * Import checks processed offline into CiviCRM from a CSV
 *
 * @author Peter Gehres <pgehres@wikimedia.org>
 */

/**
 * Implementation of hook_drush_command()
 */
function import_checks_drush_command() {
  $items = array();

  $items['import-checks'] = array(
    'description' => 
      'Import checks processed offline into CiviCRM from a CSV',
    'examples' => array( 'drush import-checks filename.csv' ),
//    'aliases' => array( 'ic' ),
  	'required-arguments' => true,
	'arguments' => array(
		'file' => 'Name of CSV file to process'
	),
	'options' => array(
		'type' => 'File format to assume for the import',
	),
  );

  return $items;
}

/**
 * Implementation of hook_drush_help()
 */
function import_checks_drush_help( $section ) {
  switch ( $section ) {
    case 'drush:import-checks':
      return dt( "Import checks processed offline into CiviCRM from a CSV" );
  }
}

/**
 * Loads lines from a file and imports into CiviCRM
 */
function drush_import_checks() {
	$args = drush_get_arguments();
	$filename = $args[1];
	$type = drush_get_option( 'type', 'ChecksFile' );

	try {
		civicrm_initialize();
		$importer = new $type( $filename );
		$importer->import();
	}
	catch ( WmfException $ex ) {
		watchdog( 'offline2civicrm', 'Import checks: error during import ' + $ex->getMessage(), NULL, WATCHDOG_ERROR );
		if ( $ex->isRejectMessage() && !$ex->isNoEmail() ) {
			watchdog( 'offline2civicrm', 'Suppressing failmail.', NULL, WATCHDOG_WARNING );
		}

		if ( $ex->isFatal() ) {
			watchdog( 'offline2civicrm', 'Import checks: fatal error, aborting process', NULL, WATCHDOG_ERROR );
			return;
		}
	} catch ( Exception $e ) {
		watchdog('offline2civicrm', 'Import checks: Unknown exception thrown during check processing: ' . $e->getMessage(), array(), WATCHDOG_ERROR );
	}
}

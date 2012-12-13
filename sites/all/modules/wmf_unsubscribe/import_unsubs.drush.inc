<?php
/**
 * @file import_unsubs.drush.inc
 *
 * Import unsubscribed email addresses into CiviCRM from a CSV
 *
 * @author Peter Gehres <pgehres@wikimedia.org>
 */

/**
 * Implementation of hook_drush_command()
 */
function import_unsubs_drush_command() {
	$items = array();

	$items['import-unsubs'] = array(
		'description' =>
		'Import unsubscribed email addresses into CiviCRM from a CSV via a queue',
		'examples' => array( 'drush import-unsubs filename.csv' ),
//    'aliases' => array( 'ic' ),
		'required-arguments' => true,
		'arguments' => array(
			'file' => 'Name of CSV file to process'
		)
	);

	return $items;
}

/**
 * Implementation of hook_drush_help()
 */
function import_unsubs_drush_help( $section ) {
	switch ( $section ) {
		case 'drush:import-unsubs':
			return dt( "Import unsubscribed email addresses into CiviCRM from a CSV via a queue" );
	}
}

/**
 *
 */
function drush_import_unsubs() {
	try{
		$args = drush_get_arguments();
		$filename = $args[1];

		if( ( $file = fopen( $filename, 'r' )) === FALSE ){
			watchdog('wmf_unsubscribe', 'Import unsubs: Could not open file for reading: ' . $filename, array(), WATCHDOG_ERROR);
			return;
		}

		$headers = _load_headers( fgetcsv( $file, 0, ',', '"', '\\') );

		while( ( $row = fgetcsv( $file, 0, ',', '"', '\\')) !== FALSE) {
			$form_state = array(
				'values' => array(
					'email' => _get_value( "email", $row, $headers ),
				),
			);

			wmf_unsubscribe_manual_form_submit( null, $form_state );
		}

	}
	catch ( Exception $e ){
		watchdog('wmf_unsubscribe', 'Import unsubs: Exception thrown during csv processing: ' . print_r( $e, true ), array(), WATCHDOG_ERROR);
	}
}

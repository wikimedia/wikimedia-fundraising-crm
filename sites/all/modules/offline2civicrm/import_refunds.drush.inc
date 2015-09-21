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

	if ( !file_exists( $filename ) ) {
		watchdog(
			'offline2civicrm',
			"Import refunds: File does not exist: $filename",
			null,
			WATCHDOG_ERROR
		);
		return;
	}

	$file = fopen( $filename, 'r' );
	if ( $file === false ) {
		watchdog(
			'offline2civicrm', 
			"Import refunds: Could not open file for reading: $filename",
			null,
			WATCHDOG_ERROR
		);
		return;
	}

	try {
		$headers = _load_headers( fgetcsv( $file, 0, ',' ) );
		$rowCount = 0;
		civicrm_initialize();
		while ( ( $row = fgetcsv( $file, 0, ',' ) ) !== FALSE ) {
			$rowCount += 1;
			$orderid = _get_value( 'Order ID', $row, $headers );
			$refundid = _get_value( 'Refund ID', $row, $headers, null );
			$currency = _get_value( 'Currency', $row, $headers );
			$amount = _get_value( 'Amount', $row, $headers );
			$date = _get_value( 'Date', $row, $headers );
			$refundType = _get_value( 'Type', $row, $headers, 'refund' );

			if ( $orderid == '' ) {
				watchdog(
					'offline2civicrm',
					"Invalid OrderID for refund on row $rowCount",
					$row,
					WATCHDOG_INFO
				);
				continue;
			}

			$contributions = wmf_civicrm_get_contributions_from_gateway_id( $processor, $orderid );
			if ( $contributions ) {
				$contribution = array_shift( $contributions );
			} else {
				watchdog(
					'offline2civicrm',
					"Could not find transaction matching trxn_id: $orderid",
					NULL,
					WATCHDOG_ERROR
				);
				continue;
			}

			// execute the refund
			wmf_civicrm_mark_refund(
				$contribution['id'],
				$refundType,
				true,
				$date,
				$refundid,
				$currency,
				$amount
			);
			watchdog(
				'offline2civicrm',
				"Marked $processor transaction $orderid refunded",
				null,
				WATCHDOG_INFO
			);
		}
	} catch ( Exception $e ) {
		watchdog( 'offline2civicrm', 'Import refunds: Exception thrown during refund processing: ' .
			print_r( $e, true ), array(), WATCHDOG_ERROR );
	}
}

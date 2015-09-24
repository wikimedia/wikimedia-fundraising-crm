<?php

/**
 * Imports refunds from a CSV file
 *
 * @author Elliott Eggleston <eeggleston@wikimedia.org>
 */
class RefundFile {

	protected $processor;
	protected $file_uri;

	/**
	 * @param string $processor name of the payment processor issuing refunds
	 * @param string $file_uri path to the file
	 */
	function __construct( $processor, $file_uri ) {
		$this->processor = $processor;
		$this->file_uri = $file_uri;
	}

	function import() {
		if ( !file_exists( $this->file_uri ) ) {
			throw new WmfException( 'FILE_NOT_FOUND', 'File not found: ' . $this->file_uri );
		}

		$file = fopen( $this->file_uri, 'r' );
		if ( $file === false ) {
			throw new WmfException( 'FILE_NOT_FOUND', 'Could not open file for reading: ' . $this->file_uri );
		}

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

			if ( $orderid === '' ) {
				watchdog(
					'offline2civicrm',
					"Invalid OrderID for refund on row $rowCount",
					$row,
					WATCHDOG_INFO
				);
				continue;
			}

			$contributions = wmf_civicrm_get_contributions_from_gateway_id( $this->processor, $orderid );
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
				"Marked {$this->processor} transaction $orderid refunded",
				null,
				WATCHDOG_INFO
			);
		}
	}
}

<?php

use SmashPig\PaymentProviders\AstroPay\Audit\AstroPayAudit;

class AstroPayAuditProcessor extends BaseAuditProcessor {
	protected $name = 'astropay';

	protected function get_audit_parser() {
		return new AstroPayAudit();
	}

	protected function get_recon_file_sort_key( $file ) {
		// Example:  wikimedia_report_2015-06-16.csv
		// For that, we'd want to return 20150616
		$parts = preg_split( '/_|\./', $file );
		$date_piece = $parts[count( $parts ) - 2];
		$date = preg_replace( '/-/', '', $date_piece );
		if ( !preg_match( '/^\d{8}$/', $date ) ) {
			throw new Exception( "Unparseable reconciliation file name: {$file}" );
		}
		return $date;
	}

	protected function get_log_distilling_grep_string() {
		return 'Redirecting for transaction:';
	}

	protected function get_log_line_grep_string( $order_id ) {
		return ":$order_id Redirecting for transaction:";
	}

	protected function parse_log_line( $logline ) {
		return $this->parse_json_log_line( $logline );
	}

	protected function merge_data( $log_data, $audit_file_data ) {
		$merged = parent::merge_data( $log_data, $audit_file_data );
		if ( $merged ) {
			unset( $merged['log_id'] );
		}
		return $merged;
	}

	protected function regex_for_recon() {
		return '/_report_/';
	}

	/**
	 * Initial logs for AstroPay have no gateway transaction id, just our
	 * contribution tracking id.
	 *
	 * @param array $transaction possibly incomplete set of transaction data
	 * @return string|false the order_id, or false if we can't figure it out
	 */
	protected function get_order_id( $transaction ) {
		if ( is_array( $transaction ) && array_key_exists( 'log_id', $transaction ) ) {
			return $transaction['log_id'];
		}
		return false;
	}
}

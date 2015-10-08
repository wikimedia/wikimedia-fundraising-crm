<?php

use SmashPig\PaymentProviders\Amazon\Audit\AuditParser;

class AmazonAuditProcessor extends BaseAuditProcessor {
	protected $name = 'amazon';

	protected function get_audit_parser() {
		return new AuditParser();
	}

	protected function get_recon_file_date( $file ) {
		// Example:  2015-09-29-SETTLEMENT_DATA_353863080016707.csv
		// For that, we'd want to return 20150929
		$parts = preg_split( '/-/', $file );
		if ( count( $parts ) !== 4 ) {
			throw new Exception( "Unparseable reconciliation file name: {$file}" );
		}
		$date = "{$parts[0]}{$parts[1]}{$parts[2]}";

		return $date;
	}

	protected function get_log_distilling_grep_string() {
		return 'Got info for Amazon donation: ';
	}

	protected function get_log_line_grep_string( $order_id ) {
		return ":$order_id Got info for Amazon donation: ";
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
		return '/SETTLEMENT_DATA|REFUND_DATA/';
	}

	/**
	 * Amazon audit parser should add our reference id as log_id.  This will
	 * be the contribution tracking id, a dash, and the attempt number.
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

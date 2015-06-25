<?php

// FIXME: case.  use SmashPig\PaymentProviders\AstroPay\Audit\AstroPayAudit;
use SmashPig\PaymentProviders\Astropay\Audit\AstropayAudit;

class AstroPayAuditProcessor extends BaseAuditProcessor {
	protected $name = 'astropay';

	protected function get_audit_parser() {
		# FIXME: "AstroPay" case
		return new AstropayAudit();
	}

	protected function get_recon_file_date( $file ) {
		// Example:  wikimedia_report_2015-06-16.csv
		// For that, we'd want to return 20150616
		$parts = preg_split( '/_|\./', $file );
		$date_piece = $parts[count( $parts ) - 2];
		$date = preg_replace( '/-/', '', $date_piece );
		if ( !preg_match( '/\d{6}/', $date ) ) {
			throw new Exception( "Unparseable reconciliation file name: {$file}" );
		}
		return $date;
	}

	protected function get_log_distilling_grep_string() {
		return 'Redirecting for transaction:';
	}

	protected function get_log_line_grep_string( $order_id ) {
		# FIXME: escaping is getting silly
		return "\"contribution_tracking_id\":\"\\?$order_id\"\\?";
	}

	protected function parse_log_line( $logline ) {
		$matches = array();
		if ( preg_match( '/[^{]*([{].*)/', $logline, $matches ) ) {
			$recon_data = json_decode( $matches[1], true );
			if ( !$recon_data ) {
				throw new Exception( "Could not parse json at: {$logline}" );
			}
			// Translate and filter field names
			$map = array(
				'amount' => 'gross',
				'country',
				'currency_code' => 'currency',
				'email',
				'fname' => 'first_name',
				'gateway',
				'language',
				'lname' => 'last_name',
				'payment_method',
				'payment_submethod',
				'referrer',
				'user_ip',
				'utm_source',
			);
			$normal = array();
			foreach ( $map as $logName => $normalName ) {
				if ( is_numeric( $logName ) ) {
					$normal[$normalName] = $recon_data[$normalName];
				} else {
					$normal[$normalName] = $recon_data[$logName];
				}
			}
			return $normal;
		} else {
			throw new Exception( "Log parse failure at: {$logline}" );
		}
	}

	/**
	 * FIXME: looks like this function should be split into normalize (empty
	 * default implementation) and merge (default impl).
	 */
	protected function normalize_and_merge_data( $normal, $recon_data ) {
		if ( empty( $normal ) || empty( $recon_data ) ) {
			$message = ": Missing one of the required arrays.\nXML Data: " . print_r( $normal, true ) . "\nRecon Data: " . print_r( $recon_data, true );
			wmf_audit_log_error( __FUNCTION__ . $message, 'DATA_WEIRD' );
			return false;
		}

		//now, cross-reference what's in $recon_data and complain loudly if something doesn't match.
		//@TODO: see if there's a way we can usefully use [settlement_currency] and [settlement_amount]
		//from the recon file. This is actually super useful, but might require new import code and/or schema change.

		$cross_check = array(
			'currency',
			'gross',
		);

		foreach ( $cross_check as $field ) {
			if ( array_key_exists( $field, $normal ) && array_key_exists( $field, $recon_data ) ) {
				if ( is_numeric( $normal[$field] ) ) {
					//I actually hate everything.
					//Floatval all by itself doesn't do the job, even if I turn the !== into !=.
					//"Data mismatch between normal gross (5) and recon gross (5)."
					$normal[$field] = (string) floatval( $normal[$field] );
					$recon_data[$field] = (string) floatval( $recon_data[$field] );
				}
				if ( $normal[$field] !== $recon_data[$field] ) {
					wmf_audit_log_error( "Data mismatch between normal $field ({$normal[$field]}] != {$recon_data[$field]}). Investigation required. " . print_r( $recon_data, true ), 'DATA_INCONSISTENT' );
					return false;
				}
			} else {
				wmf_audit_log_error( "Recon data is expecting $field but at least one is missing. Investigation required. " . print_r( $recon_data, true ), 'DATA_INCONSISTENT' );
				return false;
			}
		}

		// Just port everything.
		return array_merge( $recon_data, $normal );
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
		if ( is_array( $transaction ) && array_key_exists( 'contribution_tracking_id', $transaction ) ) {
			return $transaction['contribution_tracking_id'];
		}
		return false;
	}
}

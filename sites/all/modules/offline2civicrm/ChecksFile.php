<?php

class ChecksFile {
	function import( $filename ) {
		$required_fields = array(
			'date',
			'gross',
			'gift_source',
			'import_batch_number',
			'check_number',
			'restrictions',
		);

		ini_set( 'auto_detect_line_endings', true );
		if( ( $file = fopen( $filename, 'r' )) === FALSE ){
			watchdog('offline2civicrm', 'Import checks: Could not open file for reading: ' . $filename, array(), WATCHDOG_ERROR);
			return;
		}

		$headers = _load_headers( fgetcsv( $file, 0, ',', '"', '\\') );

		$required_columns = array(
			'Batch',
			'Check Number',
			'City',
			'Contribution Type',
			'Country',
			'Direct Mail Appeal',
			'Email',
			'Gift Source',
			'Payment Instrument',
			'Postal Code',
			'Received Date',
			'Restrictions',
			'Source',
			'State',
			'Street Address',
			'Thank You Letter Date',
			'Total Amount',
		);

		$failed = array();
		foreach ( $required_columns as $name ) {
			if ( !array_key_exists( $name, $headers ) ) {
				$failed[] = $name;
			}
		}
		if ( $failed ) {
			throw new WmfException( 'INVALID_FILE_FORMAT', "This file is missing headers: " . implode( ", ", $failed ) );
		}

		while( ( $row = fgetcsv( $file, 0, ',', '"', '\\')) !== FALSE) {
			list($currency, $source_amount) = explode( " ", _get_value( "Source", $row, $headers ) );
			$total_amount = (float)_get_value( "Total Amount", $row, $headers );

			if ( abs( $source_amount - $total_amount ) > .01 ) {
				$pretty_msg = json_encode( array_combine( array_keys( $headers ), $row ) );
				throw new WmfException( 'INVALID_MESSAGE', $pretty_msg );
			}

			$msg = array(
				"optout" => "1",
				"anonymous" => "0",
				"letter_code" => _get_value( "Letter Code", $row, $headers ),
				"contact_source" => "check",
				"language" => "en",
				"street_address" => _get_value( "Street Address", $row, $headers ),
				"supplemental_address_1" => _get_value( "Additional Address 1", $row, $headers ),
				"city" => _get_value( "City", $row, $headers ),
				"state_province" => _get_value( "State", $row, $headers ),
				"postal_code" => _get_value( "Postal Code", $row, $headers ),
				"payment_method" => _get_value( "Payment Instrument", $row, $headers ),
				"payment_submethod" => "",
				"check_number" => _get_value( "Check Number", $row, $headers ),
				"currency" => $currency,
				"original_currency" => $currency,
				"original_gross" => _get_value( "Total Amount", $row, $headers ),
				"fee" => "0",
				"gross" => _get_value( "Total Amount", $row, $headers ),
				"net" => _get_value( "Total Amount", $row, $headers ),
				"date" => strtotime( _get_value( "Received Date", $row, $headers ) ),
				"thankyou_date" => strtotime( _get_value( "Thank You Letter Date", $row, $headers ) ),
				"restrictions" => _get_value( "Restrictions", $row, $headers ),
				"gift_source" => _get_value( "Gift Source", $row, $headers ),
				"direct_mail_appeal" => _get_value( "Direct Mail Appeal", $row, $headers ),
				"import_batch_number" => _get_value( "Batch", $row, $headers ),
			);

			$contype = _get_value( 'Contribution Type', $row, $headers );
			switch ( $contype ) {
				case "Merkle":
					$msg['gateway'] = "merkle";
					break;

				case "Arizona Lockbox":
					$msg['gateway'] = "arizonalockbox";
					break;

				default:
					throw new WmfException( 'CIVI_REQ_FIELD', "Contribution Type '$contype' is unknown whilst importing checks!" );
			}

			// Attempt to get the organization name if it exists...
			// Merkle used the "Organization Name" column header where AZL uses "Company"
			$orgname = _get_value( 'Organization Name', $row, $headers, FALSE );
			if ( $orgname === FALSE ) {
				$orgname = _get_value( 'Company', $row, $headers, FALSE );
			}

			if( $orgname === FALSE ) {
				// If it's still false let's just assume it's an individual
				$msg['contact_type'] = "Individual";
				$msg["first_name"] = _get_value( "First Name", $row, $headers );
				$msg["middle_name"] = _get_value( "Middle Name", $row, $headers );
				$msg["last_name"] = _get_value( "Last Name", $row, $headers );
			} else {
				$msg['contact_type'] = "Organization";
				$msg['organization_name'] = $orgname;
			}

			// check for additional address information
			if( _get_value( 'Additional Address 2', $row, $headers ) != ''){
				$msg['supplemental_address_1'] .= ' ' . _get_value( 'Additional Address 2', $row, $headers );
			}

			// An email address is one of the crucial fields we need
			if( _get_value( 'Email', $row, $headers ) == ''){
				// set to the default, no TY will be sent
				$msg['email'] = "nobody@wikimedia.org";
			} else {
				$msg['email'] = _get_value( 'Email', $row, $headers );
			}

			// CiviCRM gets all weird when there is no country set
			// Making the assumption that none = US
			if( _get_value( 'Country', $row, $headers ) == ''){
				$msg['country'] = "US";
			} else {
				$msg['country'] = _get_value( 'Country', $row, $headers );
			}

			if ( $msg['country'] === "US" ) {
				// left-pad the zipcode
				if ( preg_match( '/^(\d{1,4})(-\d+)?$/', $msg['postal_code'], $matches ) ) {
					$msg['postal_code'] = str_pad( $matches[1], 5, "0", STR_PAD_LEFT );
					if ( !empty( $matches[2] ) ) {
						$msg['postal_code'] .= $matches[2];
					}
				}
			}

			// Generating a transaction id so that we don't import the same rows multiple times
			$name_salt = $msg['contact_type'] == "Individual" ? $msg["first_name"] . $msg["last_name"] : $msg["organization_name"];
			$msg['gateway_txn_id'] = md5( $msg['check_number'] . $name_salt );

			// check to see if we have already processed this check
			if ( $existing = wmf_civicrm_get_contributions_from_gateway_id( $msg['gateway'], $msg['gateway_txn_id'] ) ){
				// if so, move on
				watchdog('offline2civicrm', 'Contribution matches existing contribution (id: ' . $existing[0]['id'] .
					') Skipping', array(), WATCHDOG_INFO);
				continue;
			}

			$failed = array();
			foreach ( $required_fields as $key ) {
				if ( !array_key_exists( $key, $msg ) or empty( $msg[$key] ) ) {
					$failed[] = $key;
				}
			}
			if ( $failed ) {
				throw new WmfException( 'CIVI_REQ_FIELD', t( "Missing required fields :keys during check import", array( ":keys" => implode( ", ", $failed ) ) ) );
			}

			$contribution = wmf_civicrm_contribution_message_import( $msg );

			watchdog('offline2civicrm', 'Import checks: Contribution imported successfully (!id): !msg', array('!id' => $contribution['id'], '!msg' => print_r( $msg, true )), WATCHDOG_INFO);
		}

		watchdog( 'offline2civicrm', 'Import checks: finished', null, WATCHDOG_INFO );
	}
}

<?php

# FIXME: merge with Queue.php

/**
 * Hook to send complete transaction information to ActiveMQ server
 * @global string $wgStompServer ActiveMQ server name.
 * @global string $wgStompQueueName Name of the destination queue for completed transactions.
 * @param array $transaction Key-value array of staged and ready donation data.
 * @return bool Just returns true all the time. Presumably an indication that
 * nothing exploded big enough to kill the whole thing.
 */
function sendSTOMP( $transaction ) {
	$initial_msg = true;
	if ( array_key_exists( 'gateway_refund_id', $transaction ) ){
		$initial_msg = false;
	}

	$stompServer = variable_get('queue2civicrm_url', 'tcp://localhost:61613');
	if ( $initial_msg ){
		$stompQueue = variable_get('queue2civicrm_subscription', '/queue/test');
	} else {
		$stompQueue = variable_get('refund_queue', '/queue/refund-notifications_test');
	}
	
	$message = json_encode( createQueueMessage( $transaction ) );
	
	if ( $message ){

		// make a connection
		$con = new Stomp( $stompServer );

		// connect
		$con->connect();

		// send a message to the queue
		$result = $con->send( "$stompQueue", $message, array( 'persistent' => 'true' ) );

		$ret = null;
		if ( !$result ) {
			watchdog('queue2civicrm', 'Send to Q failed for this message: ' . $message, array(), WATCHDOG_ERROR);
			drush_set_error("STOMP_BAD_CONNECTION", 'Send to Q failed for this message: ' . $message);
			$ret = false;
		} else {
			$ret = true;
		}

		$con->disconnect();
	} else {
		watchdog('queue2civicrm', 'createQueueMessage failed for : ' . $message, array(), WATCHDOG_ERROR);
		$ret = false;
	}
	
	return $ret;
}

/**
 * Confirms that all fields expected by queue2civicrm are present before
 * a message is allowed to be placed into the Stomp queue.  Sets all of the
 * fields sent by Extension:DonationInterface to an empty string if not
 * sent in the original message to avoid any random issues.
 *
 * @param $transaction
 * @return array
 */
function createQueueMessage( $msg ) {
	
	if ( array_key_exists( 'gateway_refund_id', $msg ) ){
		return $msg; //just don't.
	}
	
	//do normalization first, if necessary.
	if ( !array_key_exists( 'gross', $msg ) && array_key_exists( 'amount', $msg ) && is_numeric( $msg['amount'] ) ){
		$msg = stageStompMessageFromDIKeys($msg);
	}

	// check for the required fields
	$required = array(
		'email',
		'gross',
		'original_currency',
		'gateway',
		'gateway_txn_id'
	);

	foreach ( $required as $r ){
		if ( !array_key_exists( $r, $msg ) || $msg[$r] == '' ){
			watchdog('queue2civicrm', "Required Field '$r' not found in message:" . print_r($msg, true), NULL, WATCHDOG_ERROR);
			drush_set_error("CIVI_REQ_FIELD", "Required Field '$r' not found in message:" . print_r($msg, true));
			return false;
		}
	}

	$message_defaults = array(
		'optout' => 1,
		'anonymous' => 0, // TODO: is 0 the right value?
		'comment' => "",
		'size' => "",
		'premium_language' => "",
		'utm_source' => "",
		'utm_medium' => "",
		'utm_campaign' => "",
		'language' => "",
		'referrer' => "",
		'first_name' => "",
		'middle_name' => "",
		'last_name' => "",
		'street_address' => "",
		'supplemental_address_1' => '',
		'city' => "",
		'state_province' => "",
		'country' => "",
		'postal_code' => "",
		'recurring' => FALSE, // TODO is this right?
		'payment_method' => "",
		'payment_submethod' => "",
		'response' => "",
		'currency' => "",
		'original_currency' => "",
		'original_gross' => "",
		'net' => "",
		'fee' => '0',
		'date' => time(),
	);

	// override all defaults with actual values, if they exist
	$msg = array_merge( $message_defaults, $msg );

	// why we do this, no one knows
	$msg['first_name_2'] = $msg['first_name'];
	$msg['last_name_2'] = $msg['last_name'];
	$msg['street_address_2'] = $msg['street_address'];
	$msg['supplemental_address_2'] = $msg['supplemental_address_1'];
	$msg['city_2'] = $msg['city'];
	$msg['state_province_2'] = $msg['state_province'];
	$msg['country_2'] = $msg['country'];
	$msg['postal_code_2'] = $msg['postal_code'];

	//if these two are the unfilled default value, assign them the value of the required 'gross' field.
	if ( $msg['original_gross'] === $message_defaults['original_gross'] ){
		$msg['original_gross'] = $msg['gross'];
	}
	
	if ( !array_key_exists( 'net', $msg ) || $msg['net'] === $message_defaults['net'] ){
		$msg['net'] = $msg['gross'];
	}

	// optional key, but one that can cause issues if not set properly
	if ( !$msg['recurring'] ) {
		unset( $msg['recurring'] );
	}
	
	$msg['date'] = (int) $msg['date'];

	return $msg;
}

/**
 * Arghargharghargh.
 */
function stageStompMessageFromDIKeys( $msg ){
//	foreach ( $msg as $key => $value ){
//		echo "\n'$key' => '',";
//		die();
//	}
	
	$map = array(
		'amount' => 'gross',
		'fname' => 'first_name',
		'mname' => 'middle_name',
		'lname' => 'last_name',
		'street' => 'street_address',
		'state' => 'state_province',
		'zip' => 'postal_code',
		'currency_code' => 'original_currency',
	);
	
	foreach ( $map as $di_key => $stomp_key ){
		if ( array_key_exists( $di_key, $msg ) ){
			$msg[$stomp_key] = $msg[$di_key];
			unset ( $msg[$di_key] );
		}
	}
	
	return $msg;
}

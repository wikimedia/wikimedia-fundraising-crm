<?php 
/**
 * Common functions for queue2civicrm modules 
 */

// include the database switching class
require_once( drupal_get_path( 'module', 'queue2civicrm' ) . '/queue2civicrm_db_switcher.inc' );

/**
 * Connect using the Stomp library.
 */
function _queue2civicrm_stomp_connection($renew = false) {
  static $con = NULL;
  
  if (!isset($con) || $renew == true) {
    require_once variable_get('queue2civicrm_stomp_path', drupal_get_path('module', 'queue2civicrm') . '/Stomp.php');
    watchdog('queue2civicrm', 'Attempting connection to queue server: ' . variable_get('queue2civicrm_url', 'tcp://localhost:61613'));
    
    $con = new Stomp(variable_get('queue2civicrm_url', 'tcp://localhost:61613'));
  } 

	$attempt = 0;
	while((!is_object($con) || !$con->isConnected()) && $attempt < 2){
		try {
		  ++$attempt;
		  $con = new Stomp(variable_get('queue2civicrm_url', 'tcp://localhost:61613'));
		  $con->connect();
		  register_shutdown_function('_queue2civicrm_stomp_disconnect');
		}
		catch (Stomp_Exception $e) {
		  $con = FALSE;
		  watchdog('queue2civicrm', "Queue connection failure #$attempt: " . $e->getMessage(), array(), WATCHDOG_ERROR);
		}
	}
	
	if(!is_object($con) || !$con->isConnected()){
		watchdog('queue2civicrm', "Giving up connecting to the queue.", array(), WATCHDOG_ERROR);
		drush_set_error("STOMP_BAD_CONNECTION", "Gave up connecting to the queue.");
		return false;
	}

  return $con;
}

/**
 * Disconnect. Only used as a shutdown function.
 */
function _queue2civicrm_stomp_disconnect() {
  $con = _queue2civicrm_stomp_connection();
  if($con){
	$con->disconnect();
  }
}

/**
 * Normalize the queued message
 * 
 * Decodes the message and updates some of the message fields in ways 
 * that are consistent with how we need to insert data into CiviCRM.
 * This should be useful by other modules processing contribution messages 
 * out of the queue.
 * 
 * @param mixed $msg
 * @return array
 */
function _queue2civicrm_normalize_msg( $msg ) {
	// Decode the message body.
	if ( !is_array( $msg ) ) {
		$msg = json_decode( $msg->body, true );
	}
	
	//handle the payment instrument without altering the original message
	//(just in case any downstream logic needs to switch on the raw data)
	$msg['payment_instrument'] = _queue2civicrm_get_payment_instrument( $msg );

	// if we're missing a contrib id, insert new record to the table
	// this can happen if a user somehow makes a trxn from outside the normal workflow
	// checks are ignored as they were completely offline
	if ( !isset( $msg[ 'contribution_tracking_id' ] ) ) {
		if ( array_key_exists( 'payment_method', $msg ) && strtoupper( $msg['payment_method'] ) != "CHECK" ) {

			watchdog( 'queue2civicrm', 'Contribution missing contribution_tracking_id', array(), WATCHDOG_DEBUG );
			//@fixme: For the regular transactions, this has been moved to the staging function. This needs to happen in recurring also, and then it can be removed here.
			$anonymous = ( array_key_exists( 'anonymous', $msg) && $msg['anonymous'] == true && strtoupper( $msg['anonymous'] ) != "FALSE" ) ? 1 : 0;
			$optout = ( array_key_exists( 'optout', $msg ) && $msg['optout'] == true && strtoupper( $msg['optout'] ) != "FALSE" ) ? 1 : 0;

			$contribution_tracking_id = _queue2civicrm_insert_contribution_tracking( '..' . $msg['payment_method'], 'civicrm', date('YmdHis', (int) $msg[ 'date' ] ), null, $optout, $anonymous ); //ACK! this should not be handled this way! should be dynamic!
			watchdog( 'queue2civicrm', 'Newly inserted contribution tracking id: @id', array( '@id' => $contribution_tracking_id ), WATCHDOG_DEBUG );
			$msg['contribution_tracking_id'] = $contribution_tracking_id;
		}
	}

	// Convert times to Unix timestamps.
	if ( !is_numeric( $msg['date'] ) ) {
		$msg['date'] = strtotime($msg['date']);
	}
	// if all else fails, fall back to now.
	if ( empty( $msg['date'] ) ) {
		$msg['date'] = time();
	}

	watchdog( 'queue2civicrm', 'Contribution (pre-conversion):<pre>' . check_plain(print_r($msg, TRUE)) . '</pre>' );

	$msg[ 'gateway_txn_id_orig' ] = $msg[ 'gateway_txn_id' ];
	$msg['gateway_txn_id'] .= ' ' . time();

	return $msg;
}

/**
 * Insert a record into contribution_tracking table
 * 
 * Primarily used when a record does not already exist in the table for a
 * particular transaction.  Rare, but inserting some data for a trxn when
 * absent helps facilitate better analytics.
 * 
 * @return the contribution_tracking id 
 */
function _queue2civicrm_insert_contribution_tracking( $utm_source, $utm_medium, $ts, $contrib_id=null, $optout=null, $anonymous=null ) {
  // make sure we're using the default (drupal) db
  $dbs = _queue2civicrm_get_dbs();
  $dbs->use_default();

  if ($contrib_id === null){
	$query = "INSERT INTO {contribution_tracking} ( utm_source, utm_medium, optout, anonymous, ts ) VALUES ( '%s', '%s', '%d', '%d', '%d' )";
  } else {
	$query = "INSERT INTO {contribution_tracking} ( utm_source, utm_medium, optout, anonymous, ts, contribution_id ) VALUES ( '%s', '%s', '%d', '%d', '%d', '%d' )";
  }
  db_query( $query, $utm_source, $utm_medium, $optout, $anonymous, $ts, $contrib_id );
  return db_last_insert_id( 'contribution_tracking', 'id' );
}

/**
 * Normalize contribution amounts
 * 
 * Do exchange rate conversions and set appropriate fields for CiviCRM
 * based on information contained in the message.
 * 
 * @param $msg
 * @return array
 */
function _queue2civicrm_normalize_contrib_amnts( $msg ) {
  // round the amounts and do exchange rate conversion
  $msg['fee'] = round( exchange_rate_convert($msg['original_currency'], $msg['fee'], $msg['date']), 2 );
  $msg['gross'] = round( exchange_rate_convert($msg['original_currency'], $msg['gross'], $msg['date']), 2 );
  $msg['net'] = round( exchange_rate_convert($msg['original_currency'], $msg['net'], $msg['date']), 2 );
  
  return $msg;
}

/**
 * Insert the contact record
 * 
 * Serves as a standard way for message processors to handle contact
 * insertion.  By passing a contact id, a contact can be updated.
 * 
 * @param array $msg
 * @param int $contact_id
 * @return array
 */
function _queue2civicrm_contact_insert( $msg, $contact_id=NULL ) {
	// Set defaults for optional fields in the message
	if ( !array_key_exists( 'contact_type', $msg ) ) {
		$msg['contact_type'] = "Individual";
	} elseif ( $msg['contact_type'] != "Individual" && $msg['contact_type'] != "Organization" ) {
		// looks like an unsupported type was sent, revert to default
		watchdog( 'queue2civicrm', 'Non-supported contact_type received: %msg', array( '%msg' => print_r( $msg, true )), WATCHDOG_INFO );
		$msg['contact_type'] = "Individual";
	}

	if ( !array_key_exists( 'contact_source', $msg ) ) {
		$msg['contact_source'] = "online donation";
	}

	// Honor the opt-out checkbox, if present, else, set default
	if ( !array_key_exists( 'optout', $msg ) ) {
		$msg['optout'] = 0;
	}
  
	// Create the contact record
	$contact = array(
		'id' => $contact_id,
		'contact_type' => $msg['contact_type'],
		'first_name' => $msg['first_name'],
		'middle_name' => $msg['middle_name'],
		'last_name' => $msg['last_name'],
		'sort_name' => trim( $msg['last_name'] . ', ' . $msg['first_name'], ' ,' ),
		'display_name' => trim( $msg['first_name'] . ' ' . $msg['last_name'] ),
		'do_not_trade' => $msg['anonymous'],
		'is_opt_out' => $msg['optout'],
		'contact_source' => $msg['contact_source'],
		'preferred_language' => strtolower(substr($msg['language'], 0, 2)) . '_' . strtoupper(substr($msg['country'], 0, 2)),

		// API required fields
		'version' => '3'
	);
	if ( strtolower( $msg['contact_type'] ) == "organization" ){
		unset( $contact['first_name'] );
		unset( $contact['middle_name'] );
		unset( $contact['last_name'] );

		$contact['sort_name'] = $msg['organization_name'];
		$contact['display_name'] = $msg['organization_name'];
	}

	// Attempt to insert the contact
	$contact_result = civicrm_api( "Contact", "Create", $contact );

	if ( $contact_id == NULL ){
		watchdog( 'queue2civicrm', 'Result for adding contact: %contact', array( '%contact' => print_r( $contact_result, true )), WATCHDOG_DEBUG );
	} else {
		watchdog( 'queue2civicrm', 'Result for updating contact: %contact', array( '%contact' => print_r( $contact_result, true )), WATCHDOG_DEBUG );
	}

  	if ( array_key_exists( 'is_error', $contact_result ) && $contact_result['is_error'] != 0 ){
		return false;
	}
	return $contact_result;
}

/**
 * Update the contact record
 * 
 * Serves as a standard way for message processors to handle contact
 * updates.
 * 
 * @param array $msg
 * @param int $contact_id
 * @return array
 */
function _queue2civicrm_contact_update( $msg, $contact_id ) {

  	return _queue2civicrm_contact_insert( $msg, $contact_id );

}

/**
 * Insert the location record
 * 
 * Serves as a standard way for message processors to handle location
 * insertion.
 * 
 * @param array $msg
 * @param array $contact
 * @return array
 */
function _queue2civicrm_location_insert( $msg, $contact ) {
  require_once 'api/v2/Location.php';
  $params = _queue2civicrm_location_prepare_params( $msg, $contact );
  $location_result = &civicrm_location_add( $params );
  watchdog( 'queue2civicrm', 'Result for adding location: %location', array( '%location' => print_r( $location_result, true )), WATCHDOG_DEBUG );
  return $location_result;  
}

/**
 * Update the location record
 * 
 * Serves as a standard way for message processors to handle location
 * updates.
 * 
 * @param array $msg
 * @param array $contact
 * @return array
 * @fixme upgrade to API v3, Location is deprecated, use Address, Email, etc
 */
function _queue2civicrm_location_update( $msg, $contact ) {
  require_once 'api/v2/Location.php';
  $params = _queue2civicrm_location_prepare_params( $msg, $contact );
  $location_result = &civicrm_location_update( $params );
  watchdog( 'queue2civicrm', 'Result for updating location: %location', array( '%location' => print_r( $location_result, true )), WATCHDOG_DEBUG );
  return $location_result;  
}

/**
 * Prepare the parameters for location adding/updating
 * 
 * @param $msg
 * @param $contact
 * @return array
 */
function _queue2civicrm_location_prepare_params( $msg, $contact ) {
  // unset the email address if the default is used
  // this enables us to properly dedupe contacts later on
  if( $msg[ 'email' ] == 'nobody@wikimedia.org' ){
    $msg[ 'email' ] = null;
  }

  $emails = array( 
    array(
      'email' => $msg[ 'email' ],
      'location_type_id' => 1,
    ) 
  );
  
  $address = array(
    1 => array(
	  'location_type_id' => 1, //Home
      'is_primary' => 1,
	  'street_address' => $msg['street_address'],
	  'supplemental_address_1' => $msg['supplemental_address_1'],
	  'city' => $msg['city'],
	  'state_province' => _queue2civicrm_get_state( $msg[ 'country' ], $msg['state_province'] ),
	  'postal_code' => $msg['postal_code'],
	  'country' => $msg['country'],
	  'is_billing' => 1,
    )
  );
  
  $params = array(
    'version' => '3.0',
    'contact_id' => $contact[ 'id' ],
    'address' => $address,
    'email' => $emails
  );
  
  return $params;
}

/**
 * Insert the contribution record
 * 
 * Serves as a standard way for message processors to handle contribution
 * insertion.
 * 
 * @param array $msg
 * @param array $contact
 * @param integer $recur_id   See `civicrm_contribution`.`contribution_recur_id`
 * @param integer $effort_id  The effort id - the number of successful payments for recurring payments
 * @return array
 */
function _queue2civicrm_contribution_insert( $msg, $contact, $recur_id=NULL, $effort_id=NULL ) {
	$trxn_id = strtoupper( $msg[ 'gateway' ] . ' ' . $msg[ 'gateway_txn_id' ] );
	if ( $recur_id || ( array_key_exists( "recurring", $msg ) && $msg['recurring'] ) ) {
		$trxn_id = 'RECURRING ' . $trxn_id;
	}
  
  if ( !empty($effort_id)) {
		$trxn_id .= ' - ' . (integer) $effort_id;
  }

	// Insert the contribution record
	$contribution_id = _queue2civicrm_get_civi_id( 'contribution_type_id', 'cash' );

	if ( !array_key_exists( "payment_instrument", $msg ) ){
		$msg['payment_instrument'] = _queue2civicrm_get_payment_instrument( $msg );
	}
	$payment_instrument_id = _queue2civicrm_get_civi_id( 'payment_instrument_id', $msg['payment_instrument'] );

	if ( ( $contribution_id == false ) || ( $payment_instrument_id == false ) ){
		//the errors have already been thrown in _queue2civicrm_get_civi_id. Just leave.
		return false;
	}
	if ( !array_key_exists( 'check_number', $msg ) ){
		$msg['check_number'] = NULL;
	}
	if ( !array_key_exists( 'letter_code', $msg ) ){
		$msg['letter_code'] = NULL;
	}

	$contribution = array(
		'contact_id' => $contact[ 'id' ],
		'total_amount' => $msg['gross'],
		'contribution_type_id' => $contribution_id,
		'payment_instrument_id' => $payment_instrument_id,
		'fee_amount' => $msg['fee'],
		'net_amount' => $msg['net'],
		'trxn_id' => $trxn_id,
		'receive_date' => date( 'Y-m-d H:i:s', $msg['date'] ),
		'currency' => 'USD',
		'source' => $msg['original_currency'] . ' ' . $msg['original_gross'],
		'contribution_recur_id' => $recur_id,
		'check_number' => $msg['check_number'],
		'letter_code' => $msg['letter_code'],

		// API required fields
		'version' => '3'
	);

	watchdog( 'queue2civicrm', 'Contribution array for civicrm_contribution_add(): ' . print_r($contribution, TRUE));

	$contribution_result = civicrm_api( "Contribution", "Create", $contribution );
	
	if ( array_key_exists( 'is_error', $contribution_result ) && $contribution_result['is_error'] != 0 ) {
		return false;
	}
	
	watchdog( 'queue2civicrm', 'Contribution result from civicrm_contribution_add(): ' . print_r($contribution_result, TRUE));
  
	/**
	*  Apply custom field defaults.
	*
	* Civicrm API v2 does NOT provide methods for custom groups/fields beyond creation and deleition,
	* so we hack this custom.  Hopefully this won't be forever...
	*
	* At least we can use the CiviCRM DAO stuff to stuff necessary custom data in to the contribution object.
	*
	* At the moment, all custom fields get default values for online contribution EXCEPT for 'Donor Comment'.
	*
	* NOTE: the custom field for "Gift_Information" indicates that check number is stored here.  It is not.
	*/
	$dbs = _queue2civicrm_get_dbs();
	$dbs->use_civicrm();
	$query = "SELECT id, name, table_name FROM civicrm_custom_group WHERE extends='Contribution' && is_active='1';";
	$dao = CRM_Core_DAO::executeQuery( $query ); // Execute's query using CiviCRM data object stuff
	while ( $dao->fetch() ) {
		if ( $dao->name == 'Contribution_Comments' ) {
			$comment = ( $msg[ 'comment' ] ) ? $msg[ 'comment'] : '';
			$query = "INSERT INTO {" . $dao->table_name . "} (entity_id, donor_comment) VALUES ( %d, '%s' )";
			db_query( $query, $contribution_result[ 'id' ], $comment );
		} elseif ( $dao->name == 'Gift_Data' ) {
			$lettercode = ( $msg[ 'letter_code' ] ) ? $msg[ 'letter_code'] : NULL;
			$query = "INSERT INTO {" . $dao->table_name . "} (entity_id, letter_code) VALUES ( %d, '%s' )";
			db_query( $query, $contribution_result[ 'id' ], $lettercode );
		} else { // if we dont make sure $dao->default_value has some value, Civi breaks when we try to insert
			$query = "INSERT INTO {" . $dao->table_name . "} (entity_id) VALUES (%d)";
			db_query( $query, $contribution_result[ 'id' ] );
		}
	}
	$dbs->use_default();
	return $contribution_result;
}

/**
 * Map contribution to tracking record in contribution_tracking table
 * 
 * @param array $msg
 * @param array $contribution
 */
function _queue2civicrm_update_contribution_tracking( $msg, $contribution ) {
  if (array_key_exists( 'contribution_tracking_id', $msg )) {
    $result = db_query('UPDATE {contribution_tracking} SET contribution_id=%d WHERE id=%d', $contribution['id'], $msg['contribution_tracking_id']);
    if ( !$result ) {
      watchdog( 'queue2civicrm', "There was a problem updating contribution_tracking for message: %msg", array( '%msg' => print_r( $msg, true )), WATCHDOG_ERROR );
      return FALSE;
    } else {
      watchdog( 'queue2civicrm', 'Successfuly updated contribution_tracking for message: %msg', array( '%msg' => print_r( $msg, true)));
      return TRUE;
    }
  }
}

/**
 * Insert the tag record
 * 
 * Serves as a standard way for message processors to handle tag
 * insertion.
 * 
 * @param array $contact
 * @return array
 */
function _queue2civicrm_tag_insert( $contact ) {
  require_once 'api/v2/EntityTag.php';

  $review_tag_id = _queue2civicrm_get_civi_id('tag_id', 'Review');
  //$review_tag_id = civicrm_tag_get($parameters);
  if ($review_tag_id == false){
	  return false;
  }
  
  $tag = array(
	'tag_id' => $review_tag_id,
	'contact_id' => $contact[ 'id' ]
  );
  $tag_result = &civicrm_entity_tag_add( $tag );
  return $tag_result;
}

/**
 * _queue2civicrm_normalize_msg helper function
 * Determines which civi-only payment instrument is appropriate for the current 
 * message, and resturns the civi payment instrument's human-readable display 
 * string (if it exists).   
 */
function _queue2civicrm_get_payment_instrument( $msg ){
	//default
	$default_payment_instrument = 'Gateway';
	$payment_instrument = $default_payment_instrument;
	
	if ( array_key_exists( 'payment_method', $msg ) && trim( $msg['payment_method'] ) != '' ){
	
		switch( strtolower( $msg[ 'payment_method' ] ) ){
			case 'check':
				$payment_instrument = 'Check';
				break;
			case 'bt':
				$payment_instrument = 'Bank Transfer';
				break;
			case 'cc':
				$payment_instrument = 'Credit Card';	
				switch ( strtolower( $msg['payment_submethod'] ) ){
					case 'visa':
						$payment_instrument .= ': Visa';
						break;
					case 'mc':
						$payment_instrument .= ': MasterCard';
						break;
					case 'amex':
						$payment_instrument .= ': American Express';
						break;
					case 'maestro':
						$payment_instrument .= ': Masetro';
						break;
					case 'solo':
						$payment_instrument .= ': Solo';
						break;
					case 'laser':
						$payment_instrument .= ': Laser';
						break;
					case 'jcb':
						$payment_instrument .= ': JCB';
						break;
					case 'discover':
						$payment_instrument .= ': Discover';
						break;
					case 'cb':
						$payment_instrument .= ': Carte Bleue';
						break;
				}
				break;
			case 'dd':
				$payment_instrument = 'Direct Debit';
				break;
			case 'ew':
				switch( strtolower( $msg['payment_submethod'] ) ){
					case 'ew_paypal':
						$payment_instrument = 'Paypal';
						break;
					case 'ew_webmoney':
						$payment_instrument = 'Webmoney';
						break;
					case 'ew_moneybookers':
						$payment_instrument = 'Moneybookers';
						break;
					case 'ew_cashu':
						$payment_instrument = 'Cashu';
						break;
				}
				break;
			case 'obt':
				if ( strtolower( $msg['payment_submethod'] === 'bpay' ) ){
					$payment_instrument = 'Bpay';
				}
				break;
			case 'rtbt':
				switch( strtolower( $msg['payment_submethod'] ) ){
					case 'rtbt_nordea_sweden':
						$payment_instrument = 'Nordea';
						break;
					case 'rtbt_ideal':
						$payment_instrument = 'iDeal';
						break;
					case 'rtbt_enets':
						$payment_instrument = 'Enets';
						break;
					case 'rtbt_sofortuberweisung':
						$payment_instrument = 'Sofort';
						break;
					case 'rtbt_eps':
						$payment_instrument = 'EPS';
						break;
				}
				break;
			case 'cash':
				//'cash_boleto' - Only one right now. I assume there might be more later? 
				switch ( strtolower( $msg['payment_submethod'] ) ){
					case 'cash_boleto': 
						$payment_instrument = 'Boleto';
						break;
				}
				break;	
			case 'payflowpro':
				if ( strtolower( $msg['gateway'] ) === 'payflowpro' ){
					$payment_instrument = 'Credit Card';
				}
		}
		
		//if it's still the default after all that, and it's set (or we wouldn't be here) we have a problem. 
		if ( $payment_instrument === $default_payment_instrument ){
			_queue2civicrm_failmail_instrument( $msg );
		}
		
	} else {
		//payment_method is not even in the message. Assume it's legacy... but we can tell some things about these, too...
		if ( array_key_exists( 'gateway', $msg ) && strtolower($msg['gateway']) === 'paypal' ){
			$payment_instrument = 'Paypal';
		}
	}
	
	//I was going to check to make sure the target gateway was a real thing, but: Hello, overhead. No.
	return $payment_instrument;
}


function _queue2civicrm_failmail_instrument( $message ){
	$to = variable_get('queue2civicrm_failmail', '');
	if ($to != ''){
		$params['message'] = $message;
		drupal_mail('queue2civicrm', 'payment_instrument_unmapped', $to, language_default(), $params);
	} else {
		watchdog('queue2civicrm', 'Failmail recipient address not set up!');
	}
}


/**
 * Find correct state for insertion
 * 
 * When passing CiviCRM a state abbreviation, odd things can happen - like getting the right abbreviation, but the wrong state
 * So we'll pull back the correct state/province name based off of a user's country/state abbreviation
 */
function _queue2civicrm_get_state( $country, $state ) {
  
  if ( strlen( $country ) == 2 ) {
    $query = "SELECT s.name AS state_name FROM civicrm_country c, civicrm_state_province s WHERE s.country_id=c.id AND c.iso_code='" . addslashes( $country ) . "' AND s.abbreviation='" . addslashes( $state ) . "'";
  } else {
    $query = "SELECT s.name AS state_name FROM civicrm_country c, civicrm_state_province s WHERE s.country_id=c.id AND c.name='" . addslashes( $country ) . "' AND s.abbreviation='" . addslashes( $state ) . "'";
  }
  $dbs = _queue2civicrm_get_dbs();
  $dbs->use_civicrm();
  $result = db_query( $query );
  if ( $result->num_rows === 1 ){
	  $row = db_fetch_object( $result );
	  $state = $row->state_name;
  } 
  $dbs->use_default();
  
  return $state;
}

/**
 * Manage the database switching object
 * @return object
 */
function _queue2civicrm_get_dbs() {
  static $dbs = NULL;
  
  if ( !$dbs ) {
    $dbs = new queue2civicrm_db_switcher();
  }
  
  return $dbs;
}

/**
 * Log a transaction to queue2civicrm_log in the database
 * 
 * The array needs to contain the following keys:
 *  gateway, gateway_txn_id, data, timestamp, verified
 * And optionally:
 *  cid (which is the ID of the log record to be updated)
 * 	
 * If 'cid' is present, this will update the log record, otherwise
 * this will insert a new record.
 * 
 * @param $log
 * @return mixed false on fail, cid on insert, otherwise true
 */
function _queue2civicrm_log( $log=array() ) {
  if ( empty( $log ) ) {
    return false;  
  }
  
  // make sure we're using the default db
  $dbs = _queue2civicrm_get_dbs();
  $dbs->use_default();
  
  // if cid is set in the log array, we need to update
  if ( array_key_exists('cid', $log)) {
    $query = "UPDATE {queue2civicrm_log} SET gateway='%s', gateway_txn_id='%s', data='%s', timestamp=%d, verified=%d WHERE cid=%d";
    $result = db_query( $query, $log[ 'gateway' ], $log[ 'gateway_txn_id' ], $log[ 'data' ], $log[ 'timestamp' ], $log[ 'verified' ], $log[ 'cid' ] );
    if ( $result ) $result = true;
  } else {  
    $query = "INSERT INTO {queue2civicrm_log} ( gateway, gateway_txn_id, data, timestamp, verified ) VALUES ( '%s', '%s', '%s', %d, %d )";
    $result = db_query( $query, $log[ 'gateway' ], $log[ 'gateway_txn_id' ], $log[ 'data' ], $log[ 'timestamp' ], $log[ 'verified' ] );
    if ( $result ) {
      $result = db_last_insert_id( 'queue2civicrm_log', 'cid' );
    }
  }
  
  if ( !$result ) {
    watchdog( 'queue2civicrm', 'Failed logging the transaction: %log', array( "%log" => print_r( $log, true )), WATCHDOG_ERROR );
  }
  return $result;
}

/**
 * @fixme TODO: make the v3 API calls use the wrapper function
 *
 * @param $type
 * @param $name
 * @param $fatal boolean Set this to false if you don't want to fatally end execution if the lookup fails. 
 * //TODO: Make nonfatal queries work with things other than the payment instrument.
 * @return bool
 */
function _queue2civicrm_get_civi_id($type, $name, $fatal = true) {
	static $civi_ids = array();
	$params = array('name' => $name);
	$dummy = array();
	
	if(array_key_exists($type, $civi_ids) && array_key_exists($name, $civi_ids[$type])) {
		watchdog('queue2civicrm', "Already looked up $type - $name. Returning id " . $civi_ids[$type][$name]);
		return $civi_ids[$type][$name];
	}

	switch ($type) {
		case 'contribution_type_id':
			//fixme: This is probably the upside-down way to do it. Really ought to go through the API.
			//
			require_once( drupal_get_path( 'module', 'civicrm' ) . '/../CRM/Contribute/BAO/ContributionType.php' );
			$result = CRM_Contribute_BAO_ContributionType::retrieve($params, $dummy);
			if (!is_null($result)){
				$civi_ids[$type][$name] = $result->id;
				watchdog('queue2civicrm', "Found id for contribution_type $name: " . $civi_ids[$type][$name]);
				return $civi_ids[$type][$name];
			} else {
				//make it. Or, throw an error here. Either way.
				watchdog('queue2civicrm', "Id for contribution_type $name not found. Creating...");
				$params['description'] = '';
				$params['accounting_code'] = strtoupper($name);
				$params['is_deductible'] = 1;
				$params['is_active'] = 1;
				$result = CRM_Contribute_BAO_ContributionType::add($params, $dummy);
				$civi_ids[$type][$name] = $result->id;
				watchdog('queue2civicrm', "New id for contribution_type $name :" . $civi_ids[$type][$name]);
				return $civi_ids[$type][$name];
			}
			break;
		case 'payment_instrument_id':
			require_once 'api/v3/OptionValue.php';
			$group_id = civicrm_api3_option_value_get(
				array('option_group_name' => 'payment_instrument', 'label'=> $name, 'version' => '3'));
			if($group_id['count'] == 1){
				$civi_ids[$type][$name] = $group_id['values'][strval($group_id['id'])]['value'];
				watchdog('queue2civicrm', "Found value for payment_instrument $name :" . $civi_ids[$type][$name]);
				return $civi_ids[$type][$name];
			} else {
				watchdog('queue2civicrm', "Payment Instrument '$name' not found! :", WATCHDOG_ERROR);
				if ($fatal){
					drush_set_error("CIVI_CONFIG", "Payment Instrument '$name' not found in CiviCRM! Please add it and retry.");
				} 
				return false;
			}
			break;
		case 'tag_id':
			require_once 'api/v3/Tag.php';
			$tag_id = civicrm_api3_tag_get(array('name'=> $name, 'version' => '3'));
			if (is_array($tag_id) && array_key_exists('id', $tag_id)){
				$civi_ids[$type][$name] = $tag_id['id'];
				watchdog('queue2civicrm', "Found id for Entity Tag $name :" . $civi_ids[$type][$name]);
				return $civi_ids[$type][$name];
			} else {
				watchdog('queue2civicrm', "Entity Tag '$name' not found! :", WATCHDOG_ERROR);
				drush_set_error("CIVI_CONFIG", "Entity Tag '$name' not found! Please add it and retry.");
				return false;
			}
		default:
			return false;
	}
}

/* Will return all the new drush errors since the last time you asked.
 *
 */
function _queue2civicrm_get_new_drush_errors(){
	static $oldErrors = array();
	$errors = drush_get_error_log();
	$ret = array();

	foreach ($errors as $err_code=>$lines){
		if (array_key_exists($err_code, $oldErrors)){
			$tmp = array_diff($errors[$err_code], $oldErrors[$err_code]);
			if (is_array($tmp) && !empty($tmp)){
				$ret[$err_code] = $tmp;
			}
		} else {
			$ret[$err_code] = $errors[$err_code];
		}
	}
	$oldErrors = $errors;
	return $ret;
}

/* Will return the most serious of all the new drush errors since the last time you asked,
 * with all its new lines intact.
 *
 * @param array $severity The severity levels for the errors.
 */
function _queue2civicrm_get_top_new_drush_error($severity=array()){
	$error = _queue2civicrm_get_new_drush_errors();

	if (empty($severity) || !is_array($severity)){
		$severity = array(
			'CIVI_CONFIG',
			'STOMP_BAD_CONNECTION',
			'CIVI_REQ_FIELD',
			'IMPORT_CONTACT',
			'IMPORT_CONTRIB',
			'IMPORT_TAG',
		);
	}
	
	if (empty($error)){
		return false;
	} else {	//pull the Top of Bad.
		$top_error = false;
		$top_code = false;

		foreach($severity as $code){
			if(array_key_exists($code, $error)){
				$top_code = $code;
				break;
			}
		}
		if ($top_code === false){ //unknown code in the log there. Just pull #1.
			foreach($error as $err_code=>$lines){
				$top_code = $err_code;
				break;
			}
		}

		if ($top_code !== false){
			$text = "Messages:";
			foreach ($error[$top_code] as $key=>$errormessage){
				$text .= "\n  $errormessage";
			}

			$top_error = array(
				'err_code' => $top_code,
				'err_text' => $text
			);
		}
		return $top_error;
	} 
}

/**
 * Get a Queue2civicrmTrxnCounter object
 * 
 * Creates a static Queue2civicrmTrxnCounter object that persists throughout
 * the lifetime of script execution once it's called.
 * 
 * Attempts to set the gateways to count trxns for based off of what's configured
 * (pulling from the queue2civicrm_gateways_to_monitor drupal var)
 * @return object
 */
function _queue2civicrm_trxn_counter_get() {
  static $trxn_counter;
  if ( !$trxn_counter ) {
    $gateways = explode( ",", variable_get( 'queue2civicrm_gateways_to_monitor', '' ));
    $gateways = array_map( 'trim', $gateways );
    $trxn_counter = new Queue2civicrmTrxnCounter( $gateways );
  }
  return $trxn_counter;
}

/**
 * Convenience wrapper for Queue2civicrmTrxnCounter->add
 * @param string $gateway
 * @param int $count
 */
function _queue2civicrm_trxn_counter_add( $gateway, $count=1 ) {
  $counter = _queue2civicrm_trxn_counter_get();
  $counter->add( $gateway, $count );
}

/**
 * A class to keep track of transaction counts for various payment gateways
 */
class Queue2civicrmTrxnCounter {
  protected $gateways = array();
  protected $trxn_counts = array();

  /**
   * Constructor
   * 
   * Takes an array of gateway names to keep track of trxn counts.  The
   * gateway names should be exactly as they appear in transactional messages.
   * @param array $gateways
   */
  public function __construct( array $gateways ) {
    $this->gateways = $gateways;
    foreach ( $gateways as $gateway ) {
      $this->trxn_counts[ $gateway ] = 0;
    }
  }
  
  /**
   * Increment the trxn count for a given gateway
   * @param string $gateway
   * @param int $count
   */
  public function add( $gateway, $count ) {
    if ( !in_array( $gateway, $this->gateways )) {
      return false;
    }
    $this->trxn_counts[ $gateway ] += $count;
  }
  
  /**
   * Get counts for all gateways combined or one particular gateway.
   * @param string $gateway
   * @return trxn count for all gateways ( when $gateway === null ) or specified gateway
   */
  public function get_count_total( $gateway = null ) {
    if ( $gateway ) {
      if ( !in_array( $gateway, $this->gateways )) {
        return false;
      }
      return $this->trxn_counts[ $gateway ];
    } else {
      return array_sum( $this->trxn_counts );
    }
  }
  
  /**
   * Getter for $this->trxn_counts
   */
  public function get_trxn_counts() {
    return $this->trxn_counts;
  }
}


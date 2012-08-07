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
	$msg['payment_instrument'] = wmf_civicrm_get_message_payment_instrument( $msg );
	if (!$msg['payment_instrument']) {
		_queue2civicrm_failmail_instrument($msg);
	}

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
  $dbs = wmf_civicrm_get_dbs();
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
      watchdog( 'queue2civicrm', 'Successfully updated contribution_tracking for message: %msg', array( '%msg' => print_r( $msg, true)));
      return TRUE;
    }
  }
}

/**
 * Insert the recurring contribution record
 * 
 * @todo Determine the watchdog level for errors.
 * @todo Are the dates being set with the correct value?
 * 
 * @param array $msg
 * @param integer $contact_id
 * @param array $contribution   The contribution record
 * @return boolean	Returns true on success
 */
function _queue2civicrm_contribution_recur_insert( $msg, $contact_id, $contribution ) {
	
  //try to include files that may or may not be in installed modules. 
  //TODO: This should really be a hook that only fires if something knows how to 
  //interpret it.
  //That way, we remove the hard dependency and the chances of whitescreening 
  //entire instances on code update, and a potential circular dependency that 
  //would effectively prevent installation (or un-installation) of either module. 
  $file = drupal_get_path( 'module', 'recurring_globalcollect' ) . '/recurring_globalcollect_common.inc';
  if ( file_exists( $file ) ){
	require_once( $file );
  } else {
  //XXX this is a terrible thing to do.
  	$watchdog_message = 'Recurring Globalcollect module is not installed. Cannot insert recurring subscription.';
  	watchdog( 'queue2civicrm', $watchdog_message, null, WATCHDOG_ERROR );
  	drush_set_error('IMPORT_SUBSCRIPTION', $watchdog_message);
  	return false;
  }

  
  // Get the contribution record
  if ( isset( $contribution['values'] ) && is_array( $contribution['values'] ) ) {
    $contribution_record = $contribution['values'][ $contribution['id'] ];
  }
  else {
  	  $contribution_record = $contribution;
  }
	
  $msg[ 'frequency_unit' ] = isset( $msg[ 'frequency_unit' ] ) ? $msg[ 'frequency_unit' ] : 'month';
  $msg[ 'frequency_interval' ] = isset( $msg[ 'frequency_interval' ] ) ? (integer) $msg[ 'frequency_interval' ] : 1;
  $msg[ 'installments' ] = isset( $msg[ 'installments' ] ) ? (integer) $msg[ 'installments' ] : 0;

  if ( !isset( $msg[ 'recurring' ] ) && $msg[ 'recurring' ] != 1 ) {
  	$watchdog_message = 'Payment is not marked as recurring, with the contact_id [' . $contact_id . '] $msg: ' . print_r( $msg, true );
  	watchdog( 'queue2civicrm', $watchdog_message, null, WATCHDOG_ERROR );
  	drush_set_error('IMPORT_SUBSCRIPTION', $watchdog_message);
  	return false;
  }
  
  // Allowed frequency_units
  $frequency_units = array( 'month' );
  if ( !in_array( $msg[ 'frequency_unit' ], $frequency_units ) ) {
  	$watchdog_message = 'Invalid `frequency_unit` specified [' . $msg[ 'frequency_unit' ] . ']. Supported frequency_units: %frequency_units, with the contact_id [' . $contact_id . '] $msg: ' . print_r( $msg, true );
  	watchdog( 'queue2civicrm', $watchdog_message, array( '%frequency_units' => implode( ', ', $frequency_units ) ), WATCHDOG_ERROR );
  	drush_set_error('IMPORT_SUBSCRIPTION', $watchdog_message);
  	return false;
  }

  // Frequency interval is only allowed to be 1.
  if ( $msg[ 'frequency_interval' ] !== 1 ) {
  	$watchdog_message = '`frequency_interval` is only allowed to be set to 1, with the contact_id [' . $contact_id . '] $msg: ' . print_r( $msg, true );
  	watchdog( 'queue2civicrm', $watchdog_message, null, WATCHDOG_ERROR );
  	drush_set_error('IMPORT_SUBSCRIPTION', $watchdog_message);
  	return false;
  }

  // installments is only allowed to be 0.
  if ( $msg[ 'installments' ] !== 0 ) {
  	$watchdog_message = '`installments` must be set to 0, with the contact_id [' . $contact_id . '] $msg: ' . print_r( $msg, true );
  	watchdog( 'queue2civicrm', $watchdog_message, null, WATCHDOG_ERROR );
  	drush_set_error('IMPORT_SUBSCRIPTION', $watchdog_message);
  	return false;
  }
  
  if ( empty( $contribution_record ) ) {
  	$watchdog_message = 'The contribution_record is empty, with the contact_id [' . $contact_id . '] $msg: ' . print_r( $msg, true );
  	watchdog( 'queue2civicrm', $watchdog_message, null, WATCHDOG_ERROR );
  	drush_set_error('IMPORT_SUBSCRIPTION', $watchdog_message);
  	return false;
  }

  // trxn_id must be set.
  if ( empty( $contribution_record[ 'trxn_id' ] ) ) {
  	$watchdog_message = '`trxn_id` must be set and not empty, with the contact_id [' . $contact_id . '] $msg: ' . print_r( $msg, true ) . ' and $contribution_record:'  . print_r( $contribution_record, true );
  	watchdog( 'queue2civicrm', $watchdog_message, null, WATCHDOG_ERROR );
  	drush_set_error('IMPORT_SUBSCRIPTION', $watchdog_message);
  	return false;
  }
  
  $msg[ 'cycle_day' ] = (integer) date( 'j', $msg[ 'date' ] );
  
  //TODO: This should probably be in a more universal library. What we're 
  //looking up here is not technically GC-specific. 
  //Moving it somewhere that looks more generic would remove the weird looking 
  //dependency on GC recurring. 
  $next_sched_contribution = _recurring_globalcollect_get_next_sched_contribution_date_for_month( $msg );
  
  // Remove by exploding and trimming.
  $trxn_id_exploded = explode( ' ', $contribution_record[ 'trxn_id' ] );
  array_pop( $trxn_id_exploded );
  $trxn_id = implode( ' ', $trxn_id_exploded );
  
  // Remove by last space
  //$trxn_id = substr( $contribution_record[ 'trxn_id' ], 0 , strrpos($contribution_record[ 'trxn_id' ], ' ' ) );
  //die( eval('return  __FILE__ . date(\'r\') . "\n";') . "\$trxn_id: [" . $trxn_id . "]\n" );
  
  $query = "INSERT INTO {civicrm_contribution_recur}
    (
  		contact_id,
  		amount,
  		currency,
  		frequency_unit,
  		frequency_interval,
  		installments,
  		start_date,
  		create_date,
  		processor_id,
  		cycle_day,
  		next_sched_contribution,
  		trxn_id
  	) 
  	VALUES ( %d, %f, '%s', '%s', %d, %d, '%s', '%s', %d, '%s', '%s', '%s' )";
  $queue2civicrm_dbs = wmf_civicrm_get_dbs();
  $queue2civicrm_dbs->use_civicrm();
  $result = db_query( $query, 
    // contact
  	$contact_id,
  	//amount
    $msg[ 'net' ],
    //currency
    $msg[ 'currency' ],
    //frequency_unit
    $msg[ 'frequency_unit' ],
    //frequency_interval
    $msg[ 'frequency_interval' ],
    //installments
    $msg[ 'installments' ],
    //start_date
    date( 'Y-m-d H:i:s', $msg[ 'date' ] ),
    //create_date
    date( 'Y-m-d H:i:s', $msg[ 'date' ] ),
    //processor_id
    1,
    //cycle_day
    $msg[ 'cycle_day' ],
    //next_sched_contribution
    $next_sched_contribution,
    //trxn_id
    $trxn_id
  );

  $contribution_recur_id = ( $result ) ? db_last_insert_id( 'civicrm_contribution_recur', 'id' ) : null;

  //die( eval('return  __FILE__ . date(\'r\') . "\n";') . "\$contribution_recur_id" . print_r($contribution_recur_id, true) . "\n" );
  
  // Link the contribution to the recurring payment
  $link_contribution_status = _queue2civicrm_update_contribution_for_recurring( $contribution['id'], $contribution_recur_id );
  
  $queue2civicrm_dbs->use_default(); 
	
  return $link_contribution_status;
}

/**
 * Link a recurring payment to the contribution record
 * 
 * @param integer $contribution_id
 * @param integer $contribution_recur_id
 */
function _queue2civicrm_update_contribution_for_recurring( $contribution_id, $contribution_recur_id ) {
  
  // contribution_id must be set and not null or zero
  if ( empty( $contribution_id ) ) {
  	$watchdog_message = '`contribution_id` must be set and not empty.';
  	watchdog( 'queue2civicrm', $watchdog_message, null, WATCHDOG_ERROR );
  	drush_set_error('IMPORT_SUBSCRIPTION', $watchdog_message);
  	return false;
  }
  
  // contribution_recur_id must be set and not null or zero
  if ( empty( $contribution_recur_id ) ) {
  	$watchdog_message = '`contribution_recur_id` must be set and not empty.';
  	watchdog( 'queue2civicrm', $watchdog_message, null, WATCHDOG_ERROR );
  	drush_set_error('IMPORT_SUBSCRIPTION', $watchdog_message);
  	return false;
  }

  $result = db_query('UPDATE {civicrm_contribution} SET contribution_recur_id=%d WHERE id=%d', (integer) $contribution_recur_id, (integer) $contribution_id);

  if ( !$result ) {
    watchdog( 'queue2civicrm', 'There was a problem linking the contribution [%contribution_id] to the recurring contribution [%contribution_recur_id]', array( '%contribution_id' => $contribution_id, '%contribution_recur_id' => $contribution_recur_id ), WATCHDOG_ERROR );
  	drush_set_error('IMPORT_SUBSCRIPTION', 'There was a problem linking the contribution [' . $contribution_id . '] to the recurring contribution [' . $contribution_recur_id . ']');
    return false;
  } else {
    watchdog( 'queue2civicrm', 'Successfully linked the contribution  [%contribution_id] to the recurring contribution [%contribution_recur_id]', array( '%contribution_id' => $contribution_id, '%contribution_recur_id' => $contribution_recur_id ) );
    return true;
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

  $review_tag_id = wmf_civicrm_get_civi_id('tag_id', 'Review');
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


function _queue2civicrm_failmail_instrument( $message ){
	$to = variable_get('wmf_common_failmail', '');
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
  $dbs = wmf_civicrm_get_dbs();
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
  $dbs = wmf_civicrm_get_dbs();
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


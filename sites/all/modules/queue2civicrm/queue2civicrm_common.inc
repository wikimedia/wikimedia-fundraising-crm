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


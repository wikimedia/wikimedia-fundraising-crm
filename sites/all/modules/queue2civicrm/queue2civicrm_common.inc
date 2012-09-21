<?php 
/**
 * Common functions for queue2civicrm modules 
 */

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
  $dbs->push( 'default' );
  
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


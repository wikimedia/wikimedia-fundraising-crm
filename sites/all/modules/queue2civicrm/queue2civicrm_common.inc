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

  // Replace any nulls with the empty string.
  foreach ( $log as $key => $value ) {
      if ( $value === null ) {
          $log[$key] = '';
      }
  }

  // make sure we're using the default db
  $dbs = wmf_civicrm_get_dbs();
  $dbs->push( 'default' );
  
  // if cid is set in the log array, we need to update
  if ( array_key_exists('cid', $log)) {
    $result = db_update( 'queue2civicrm_log' )->fields( array(
        'gateway' => $log[ 'gateway' ],
        'gateway_txn_id' => $log[ 'gateway_txn_id' ],
        'data' => $log[ 'data' ],
        'timestamp' => $log[ 'timestamp' ],
        'verified' => $log[ 'verified' ],
    ) )->condition( 'cid', $log[ 'cid' ] )->execute();
  } else {  
    $result = db_insert('queue2civicrm_log')->fields( array(
        'gateway' => $log[ 'gateway' ],
        'gateway_txn_id' => $log[ 'gateway_txn_id' ],
        'data' => $log[ 'data' ],
        'timestamp' => $log[ 'timestamp' ],
        'verified' => $log[ 'verified' ],
    ) )->execute();
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

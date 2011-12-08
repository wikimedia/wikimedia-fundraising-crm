<?php 
/**
 * Common functions for limbo modules 
 */

/**
 * Normalize the queued message
 * 
 * Decodes the message and updates some of the message fields in ways 
 * that are consistent with how we need to insert data into Civi.  
 * This should be useful by other modules processing contribution messages 
 * out of the queue.
 * 
 * @param mixed $msg
 * @return array
 */
function _limbo_normalize_msg( $msg ) {
  // Decode the message body.
  if ( !is_array( $msg ) ) {
    $msg = json_decode($msg->body, true);
  }

  // Convert times to Unix timestamps.
  if (!is_integer($msg['date'])) {
    $msg['date'] = strtotime($msg['date']);
  }
	
  watchdog('limbo', 'Normalized message:<pre>' . check_plain(print_r($msg, TRUE)) . '</pre>');
  
  $msg[ 'gateway_txn_id_orig' ] = $msg[ 'gateway_txn_id' ];
  $msg['gateway_txn_id'] .= ' ' . time();
  
  return $msg;
}

/**
 * Insert a record into queue2civicrm_limbo table
 * 
 * This method should be called when a message has been removed from the limbo queue.
 * 
 * This will insert any record passed to this function. 
 *
 * @return the queue2civicrm_limbo_id 
 */
function _limbo_insert_queue2civicrm_limbo($data) {
	
  $keys = array(
    'contribution_tracking_id',
    'order_id',
    'gateway',
    'payment_method',
    'payment_submethod',
    'timestamp',
    'data',
    'queue2civicrm_limbo_id',  	  
  );
  
  // Make sure all the keys exist in data.
  foreach($keys as $key) {
  	  if ($key=='queue2civicrm_limbo_id') {
  	  	  $data['queue2civicrm_limbo_id'] = empty($data['queue2civicrm_limbo_id']) ? null : $data['queue2civicrm_limbo_id'];
  	  }
  	  else{
  	  	  $data[$key] = isset($data[$key]) ? $data[$key] : '';
  	  }
  }
  
  // make sure we're using the default (drupal) db
  $dbs = _queue2civicrm_get_dbs();
  $dbs->use_default();

  if ($data['queue2civicrm_limbo_id'] === null){
	$query = "INSERT INTO {queue2civicrm_limbo} ( contribution_tracking_id, order_id, gateway, payment_method, payment_submethod, timestamp, data ) VALUES ( '%d', '%s', '%s', '%s', '%s', '%d', '%s' )";
    db_query( $query, $data['contribution_tracking_id'], $data['order_id'], $data['gateway'], $data['payment_method'], $data['payment_submethod'], $data['timestamp'], $data['data'] );
  } else {
	$query = "INSERT INTO {queue2civicrm_limbo} ( queue2civicrm_limbo_id, contribution_tracking_id, order_id, gateway, payment_method, payment_submethod, timestamp, data ) VALUES ( '%d', '%d', '%s', '%s', '%s', '%s', '%d', '%s' )";
    db_query( $query, $data['queue2civicrm_limbo_id'], $data['contribution_tracking_id'], $data['order_id'], $data['gateway'], $data['payment_method'], $data['payment_submethod'], $data['timestamp'], $data['data'] );
  }
  return db_last_insert_id( 'queue2civicrm_limbo', 'queue2civicrm_limbo_id' );
}


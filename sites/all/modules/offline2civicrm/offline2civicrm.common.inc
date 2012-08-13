<?php

/**
 * Loads the column headers into an array so that they can be used independent
 * of the column order when generating messages
 *
 * @param $row Array containing the column headers for the csv
 * @return Array mapping the header keys to the column index
 */
function _load_headers( $row ){
  $header_keys = array();

  # trimming the " from each side
  foreach( $row as $i => $k ) {
    $header_keys[trim( $k, '"' )] = $i;
  }
  watchdog('offline2civicrm', 'Import checks: Column headers loaded from file', array(), WATCHDOG_INFO);

  return $header_keys;
}

function _get_value( $column, $row, $headers, $default='' ){

  if( !array_key_exists( $column, $headers ) ){
    return $default;
  } else {
    return $row[ $headers[ $column ] ];
  }
}

function _check_existing( $msg ){
  global $dbs;

  if($dbs===null){
    $dbs = wmf_civicrm_get_dbs();
  }

  if(is_array($msg)){
    $trxn_id = $msg['payment_method'] . ' ' . $msg['gateway_txn_id'];
  }
  else{
    $trxn_id = $msg;
  }

  $dbs->use_civicrm();
  $query = "SELECT id, trxn_id FROM civicrm_contribution WHERE trxn_id LIKE '%s%' LIMIT 1;";
  $result = db_query($query, $trxn_id);
  while( $row = db_fetch_array($result) ) {
    $contribution = array(
      'id' => $row['id'],
      'trxn_id' => $row['trxn_id']
    );
    $dbs->use_default();

    return $contribution;
  }
  $dbs->use_default();

  return false;
}





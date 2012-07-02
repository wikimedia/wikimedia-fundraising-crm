<?php

if ( function_exists( 'drupal_get_path' ) ) {
  require_once( drupal_get_path( 'module', 'queue2civicrm' ) . '/queue2civicrm_db_switcher.inc' );
  require_once( drupal_get_path( 'module', 'queue2civicrm' ) . '/queue2civicrm_stomp.inc' );
}

function get_payment_instrument( $name ){
	civicrm_initialize();
	
	$payment_instrument = civicrm_api( "OptionValue", "Get", array(
		"version" => '3',
		"option_group_name" => 'payment_instrument',
		"label" => $name
	) );
	# check for API errors and log the action
	if( is_array( $payment_instrument ) && array_key_exists( 'is_error', $payment_instrument ) && $payment_instrument['is_error'] == "0" ) {
		# valid API result
		if( array_key_exists( "id", $payment_instrument ) && array_key_exists( "values", $payment_instrument )
				&& is_array( $payment_instrument['values'] ) && array_key_exists( strval( $payment_instrument['id'] ),
			$payment_instrument['values'] )
		) {
			$payment_instrument = $payment_instrument['values'][$payment_instrument['id']];
			watchdog( 'offline2civicrm', "Payment instrument found for name: " . $payment_instrument['label'], array(), WATCHDOG_INFO );
		} else {
			watchdog( 'offline2civicrm', "Payment instrument not found for $name: ", array(), WATCHDOG_ERROR );
			throw new Exception();
		}
	} else {
		watchdog( 'offline2civicrm', "API Error while retrieving payment instrument: " . print_r( $payment_instrument, true ), array(), WATCHDOG_ERROR );
		throw new Exception();
	}

	return $payment_instrument;
}

/**
 * Retrieves an object representing the contribution type specified
 *
 * @todo Add error checking
 * @todo Implement ContributionType in CiviCRM API
 *
 * @param $name The name of the contribution type to retrieve
 * @return CRM_Contribute_BAO_ContributionType|object
 */
function get_contribution_type( $type ){
	civicrm_initialize();
	
	# ContributionType is not implemented in the API
	require_once( DRUPAL_ROOT . '/sites/all/modules/civicrm/CRM/Contribute/BAO/ContributionType.php' );

	$p = array( "accounting_code" => $type );
	$q = array();
	$contribution_type = CRM_Contribute_BAO_ContributionType::retrieve( $p, $q );

	return $contribution_type;
}

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
    $dbs = new queue2civicrm_db_switcher();
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





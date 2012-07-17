<?php
/**
 * @file import_chargebacks.drush.inc
 *
 * Import chargebacks from csv into CiviCRM
 *
 * @author Peter Gehres <pgehres@wikimedia.org>
 */

if ( function_exists( 'drupal_get_path' ) ) {
  require_once( drupal_get_path( 'module', 'offline2civicrm' ) . '/offline2civicrm.common.inc' );
}

/**
 * Implementation of hook_drush_command()
 */
function import_chargebacks_drush_command() {
  $items = array();

  $items['import-chargebacks'] = array(
    'description' => 'Import chargebacks into CiviCRM from a csv',
    'examples' => array( 'drush import-chargebacks globalcollect filename.csv' ),
  	'required-arguments' => true,
	  'arguments' => array(
      'processor' => 'Name of the source payment processor',
		  'file' => 'Name of csv file to process'
	  )
  );

  return $items;
}

/**
 * Implementation of hook_drush_help()
 */
function import_chargebacks_drush_help( $section ) {
  switch ( $section ) {
    case 'drush:import-chargebacks':
      return dt( "Import chargebacks into CiviCRM from a csv" );
  }
}

/**
 *
 */
function drush_import_chargebacks($processor=NULL, $filename=NULL){

  if(!file_exists($filename)){
    watchdog('offline2civicrm', 'Import chargebacks: File does not exist: ' . $filename, array(), WATCHDOG_ERROR);
  }

  switch(strtoupper($processor)){
    case 'GLOBALCOLLECT':
      civicrm_initialize();
      _offline2civicrm_import_chargebacks_globalcollect($filename);
      break;
    default:
      watchdog('offline2civicrm', 'Import chargebacks: Unsupported processor' . $processor, array(), WATCHDOG_ERROR);
      return;
  }
}

function _offline2civicrm_import_chargebacks_chargeback_trxn($contribution_id){

  print "\tCharging back $contribution_id\n";

  $contribution = civicrm_api("Contribution", "Get", array(
    "version" => "3",
    "id" => $contribution_id
  ));

  if(!WMFCiviAPICheck::check_api_contribution($contribution, $contribution_id)){
    watchdog('offline2civicrm', "Contribution (id: $contribution_id) could not be loaded via CiviCRM API: " .
      print_r($contribution, TRUE), WATCHDOG_ERROR);
    return false;
  }

  $contribution_flat = WMFCiviAPICheck::check_api_simplify($contribution, $contribution_id);

  if($contribution_flat === false){
    watchdog('offline2civicrm', "Contribution could not be extracted from API response: " .
      print_r($contribution, TRUE), WATCHDOG_ERROR);
    return false;
  }

  $contribution = $contribution_flat;

  $contribution["total_amount"] = "0.00";
  $contribution["net_amount"] = "0.00";
  $contribution["contribution_source"] = "RFD CHARGEDBACK (" . $contribution["contribution_source"] . ")";

  $updated = civicrm_api("Contribution", "Update", array_merge($contribution, array('version' => '3',)));

  watchdog('offline2civicrm', "Updated contribution: " . print_r($updated, true), WATCHDOG_INFO);

  return true;

}

function _offline2civicrm_import_chargebacks_globalcollect($filename){
  if( ( $file = fopen( $filename, 'r' )) === FALSE ){
    watchdog('offline2civicrm', 'Import chargebacks: Could not open file for reading: ' . $filename, array(), WATCHDOG_ERROR);
  }

  try{
    // GlobalCollect actually supplies a semicolon separated file
    $headers = _load_headers( fgetcsv( $file, 0, ';') );

    while( ( $row = fgetcsv( $file, 0, ';')) !== FALSE) {

      $trxn_id = "GLOBALCOLLECT ";

      $orderid = _get_value('Order ID', $row, $headers);
      $effortid = _get_value('Effort ID', $row, $headers);

      if($orderid == ''){
        watchdog('offline2civicrm', "Invalid OrderID for chargeback on row", WATCHDOG_INFO);
        continue;
      }
      $trxn_id .= $orderid;
      if(intval($effortid) > 1){
        // we only use the Effort ID on recurring transactions, and then only the subsequent ones
        $trxn_id = "RECURRING " . $trxn_id . "-" . $effortid;
      }

      $contribution = _check_existing( $trxn_id );

      if($contribution === false){
        // the cheap query failed, go big or go home
        $contribution = _check_existing( "%GLOBALCOLLECT $orderid" );

        if($contribution === false){
          // still nothing, I'm outta guesses, lets break and give a human a try
          watchdog('offline2civicrm', "Could not find transaction matching trxn_id: " .
            print_r($row, TRUE), WATCHDOG_ERROR);
          continue;
        }
      }
      if(!array_key_exists('id', $contribution)){
        watchdog('offline2civicrm', "Contribution ID not found for trxn_id: " .
          print_r($row, TRUE) . print_r($contribution, TRUE), WATCHDOG_ERROR);
        continue;
      }

      // execute the chargeback
      _offline2civicrm_import_chargebacks_chargeback_trxn($contribution['id']);
    }
  } catch ( Exception $e ){
    watchdog('offline2civicrm', 'Import chargebacks: Exception thrown during chargeback processing: ' .
      print_r( $e, true ), array(), WATCHDOG_ERROR);
  }
}

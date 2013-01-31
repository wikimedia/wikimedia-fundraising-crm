<?php
/**
 * @file import_chargebacks.drush.inc
 *
 * Import chargebacks from csv into CiviCRM
 *
 * @author Peter Gehres <pgehres@wikimedia.org>
 */

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
  require_once 'offline2civicrm.common.inc';

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
      print_r($contribution, TRUE), NULL, WATCHDOG_ERROR);
    return false;
  }

  $contribution_flat = WMFCiviAPICheck::check_api_simplify($contribution, $contribution_id);

  if($contribution_flat === false){
    watchdog('offline2civicrm', "Contribution could not be extracted from API response: " .
      print_r($contribution, TRUE), NULL, WATCHDOG_ERROR);
    return false;
  }

  $contribution = $contribution_flat;

  if(substr($contribution["contribution_source"], 0, 3) == "RFD"){
      // the contribution has already been charged back or refunded
      return true;
  }

  $contribution["total_amount"] = "0.00";
  $contribution["net_amount"] = "0.00";
  $contribution["contribution_source"] = "RFD CHARGEDBACK (" . $contribution["contribution_source"] . ")";

  $updated = civicrm_api("Contribution", "Update", array_merge($contribution, array('version' => '3',)));

  watchdog('offline2civicrm', "Updated contribution: " . print_r($updated, true), NULL, WATCHDOG_INFO);

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

      $orderid = _get_value('Order ID', $row, $headers);
      $effortid = _get_value('Effort ID', $row, $headers);

      if($orderid == ''){
        watchdog('offline2civicrm', "Invalid OrderID for chargeback on row", NULL, WATCHDOG_INFO);
        continue;
      }
      if(intval($effortid) > 1){
        // we only use the Effort ID on recurring transactions, and then only the subsequent ones
        $orderid .= "-{$effortid}";
      }

      if ( $contributions = wmf_civicrm_get_contributions_from_gateway_id( 'globalcollect', $orderid ) ) {
        $contribution = array_shift( $contributions );
      } else {
        // still nothing, I'm outta guesses, lets break and give a human a try
        watchdog('offline2civicrm', "Could not find transaction matching trxn_id: " .
          print_r($row, TRUE), NULL, WATCHDOG_ERROR);
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

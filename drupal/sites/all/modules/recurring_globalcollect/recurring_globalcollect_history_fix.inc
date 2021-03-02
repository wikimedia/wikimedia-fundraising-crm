<?php

/**
 * This was a live-hacky script to fix a situation where, due to batch errors 
 * not being reset in the standalone adapter, many successful transactions were 
 * marked as failures when they actually went through. 
 * 
 * Overview: 
 * 1) Pull all the globalcollect transactions marked as failures
 * 2) Check the xml request/response logged in watchdog to see what actually happened. 
 * 3) If it was a successful transaction, un-record the failure and insert the transaction into civi like normal. 
 *	Otherwise, continue. 
 * 
 * Saving this for posterity. 
 * -khorn
 */
function recurring_globalcollect_fix_false_failures(){
	$questionable = recurring_globalcollect_get_all_recorded_failures();
	$hits = count($questionable);
	echo "Found failure hit count: $hits \n";
	
	//so it doesn't get away from me. 
	$hard_stop = 10;
	$fixed = array();
	
	foreach ($questionable as $subscription){
		$transaction = WmfTransaction::from_unique_id( $subscription['trxn_id'] );
		$civi_recur_id = $subscription['id'];
		$gc_id = $transaction->gateway_txn_id;
		$actual_result = recurring_globalcollect_check_xml_in_watchdog($gc_id);
		if ($actual_result){ //we found xml that contains no errors, and it's already sitting in $actual_result.
			--$hard_stop;
			//get the effort id out of the actual
			
			$effortID = NULL;
			$xmlObj = new DomDocument( '1.0' );
			$xmlObj->loadXML( $actual_result );
			
			foreach ( $xmlObj->getElementsByTagName( 'EFFORTID' ) as $node ) {
				$effortID = trim( $node->nodeValue );
			}
			if (!is_numeric($effortID)){
				die( "$effortID is not a numeric effort ID. Aborting $gc_id \n" );
			}
			
			echo "Rectifying $gc_id, effort $effortID";
		
			
			//First, unwind the subscription and then process as if nothing ever went wrong. 
			//recurring_globalcollect_undo_failure($civi_recur_id);
			recurring_globalcollect_record_old_success( $subscription, $effortID );
			echo "Done processing $gc_id. PLEASE CHECK TO MAKE SURE IT IS OKAY.\n";
			$fixed[] = $civi_recur_id;
		}
		if ($hard_stop <= 0){
			$in_clause = implode(', ', $fixed);
			echo $in_clause . "\n";
			die("Hit the hard stop on $gc_id.\n");
		}
	}
}

function recurring_globalcollect_get_all_recorded_failures(){
	/**
	 * values we need to have in $subscription: 
	 * 	$subscription['contact_id'];
	 * $subscription['amount'];
	 * $subscription['trxn_id'];
	 * $subscription['currency']
	 * $subscription['id']
	 */
	
	$sql = "SELECT id, trxn_id, contact_id, amount, currency from civicrm_contribution_recur WHERE trxn_id LIKE 'RECURRING GLOBALCOLLECT%' AND failure_count > 0";
	$dbs = wmf_civicrm_get_dbs();
	$dbs->push( 'civicrm' );

	$ret = db_query( $sql );
	$result = array();
	while ( $tmp = $ret->fetchAssoc() )  {
		if ( $tmp ){
			$result[] = $tmp;
		}
	}
	return $result;
}

function recurring_globalcollect_check_xml_in_watchdog( $gc_id ){
	$sql = "select message from watchdog where type='recurring_global' AND message LIKE '%&lt;ORDERID&gt;%d&lt;/ORDERID&gt;%'";
	$dbs = wmf_civicrm_get_dbs();
	$dbs->push( 'default' );
	$ret = db_query( $sql, $gc_id );
	$result = array();
	while ( $tmp = $ret->fetchAssoc() )  { 
		if ( $tmp ){
			$result[] = $tmp;
		}
	}
	
	// there should be two records in here. If either starts with "Receiving response [successful]:", it was a success and should be marked as such.
	foreach ($result as $message){
		if (strstr($message['message'], '&lt;ERROR&gt;')){
			return false;
		}
		if (strstr($message['message'], 'Receiving response')){
			$start = strpos($message['message'], '<pre>') + 5;
			$length = strpos($message['message'], '</pre>') - $start;
			$xml = substr($message['message'], $start, $length);
			$xml = html_entity_decode($xml);
			return $xml;
		}
	}
}

function recurring_globalcollect_undo_failure($civi_recur_id){
	$dbs = wmf_civicrm_get_dbs();
	$dbs->push( 'civicrm' );
	//undo everything we did before. 
	$contribution_status_id = 1; //1 is for "Good". 
	
    // The payment failed and is being marked as a failure.
    $query = 'UPDATE civicrm_contribution_recur SET';
    $query .= ' `contribution_status_id` = %d,';
    $query .= ' `failure_count` = `failure_count` - 1,';
    $query .= ' `failure_retry_date` = NULL';
    $query .= ' WHERE id = %d';
    db_query($query, $contribution_status_id, $civi_recur_id);

  $affected_rows = (integer) db_affected_rows();

  return $affected_rows;
}

function recurring_globalcollect_record_old_success( $subscription, $effort_id ){
	/**
	 * values we need to have in $subscription: 
	 * 	$subscription['contact_id'];
	 * $subscription['amount'];
	 * $subscription['trxn_id'];
	 * $subscription['currency']
	 * $subscription['id']
	 */

      $transaction = WmfTransaction::from_unique_id( $subscription['trxn_id'] );
      
      $msg = array();
      $msg['fee'] = 0.00;
      
      $msg['gateway'] = 'globalcollect';
      $msg['gross'] = $subscription['amount'];
      $msg['net'] = $msg['gross'] - $msg['fee'];
      //$msg['contribution_id'] = $result['contribution_id'];
      $msg['gateway_txn_id'] = $transaction->gateway_txn_id;
      $msg['payment_method'] = 'cc';
      $msg['payment_submethod'] = '';
      $msg['date'] = time();
      $msg['original_currency'] = $subscription['currency'];
      $msg['original_gross'] = $msg['gross'];

      $msg['contact_id'] = $subscription['contact_id'];
      $msg['contribution_recur_id'] = $subscription['id'];
      $msg['effort_id'] = $effort_id;

      $contribution = wmf_civicrm_contribution_message_import( $msg );

      $contribution['contribution_id'] = $contribution['id'];
      unset( $contribution['id'] );
      
      // Send thank you email, other post-import things
      if ( $contribution ){
        module_invoke_all( 'recurring_globalcollect', $contribution );
      } else {
        $message = "Contribution could not be added to civicrm, for the following message: " . print_r( $msg, true);
        watchdog('recurring_globalcollect', $message, null, WATCHDOG_ERROR);
        drush_set_error("CIVI_INSERT", $message, true);
      }
   
      $affected_rows = _recurring_globalcollect_update_record_success($subscription['id']);
}

?>

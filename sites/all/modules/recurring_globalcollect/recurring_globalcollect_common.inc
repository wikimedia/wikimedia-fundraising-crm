<?php 
/**
 * Common functions for recurring_globalcollect modules 
 */

require_once 'errors.inc';


/**
 * Select one payment by the primary key.
 * 
 * @return false|object
 */
function recurring_globalcollect_get_payment_by_id($id)
{
  require_once( drupal_get_path( 'module', 'wmf_civicrm' ) . '/db_switcher.inc' );

  // make sure we're using the default (civicrm) db
  $dbs = wmf_civicrm_get_dbs();
  $dbs->use_civicrm();

  $query = 'SELECT `civicrm_contribution_recur`.* FROM `civicrm_contribution_recur` WHERE `civicrm_contribution_recur`.`id` = %d LIMIT 1';
  
  $result = db_fetch_object( db_query( $query, $id ));

  // Restore the default connection.
  $dbs->use_default();

  // If nothing found, return false.
  if (empty($result)) {
    
    $result = false;
  }
 
  return $result;
}

/**
 * Select one recurring payment by `next_sched_contribution`, that needs to be processed for the current day.
 *
 * Example query called with standard options and the date set to: 2012-01-01
 *
 * SELECT `civicrm_contribution_recur`.* FROM `civicrm_contribution_recur`
 *  WHERE `civicrm_contribution_recur`.`next_sched_contribution`
 *   BETWEEN '2012-01-01 00:00:00' AND '2012-01-01 23:59:59'
 *  AND `civicrm_contribution_recur`.`trxn_id` LIKE 'RECURRING GLOBALCOLLECT%'
 *  AND ( `civicrm_contribution_recur`.`end_date` IS NULL )
 *  AND ( `civicrm_contribution_recur`.`failure_retry_date` IS NULL )
 *  AND `civicrm_contribution_recur`.`failure_count` = 0
 *  AND `civicrm_contribution_recur`.`contribution_status_id` = 1
 * LIMIT 1
 * 
 * options:
 * - date           string    Uses strtotime() to parse the date
 * - $limit         integer   Number of records to pull. Default is 1.
 * 
 * @todo The field `civicrm_payment_processor`.`payment_processor_type` should be set.
 * @todo Implement $contributionStatus = CRM_Contribute_PseudoConstant::contributionStatus( null, 'name' );
 * 
 * @return false|object
 */
function recurring_globalcollect_get_next_sched_contribution($limit = 1, $date = 'now')
{
  // make sure we're using the default (civicrm) db
  $dbs = wmf_civicrm_get_dbs();
  $dbs->use_civicrm();

  $date = date('Y-m-d', strtotime($date));
  
  $start_day = $date . ' 00:00:00';
  $end_day = $date . ' 23:59:59';
  
  $start_day_stamp = strtotime($start_day);
  $end_day_stamp = strtotime($end_day);

  $recurring_globalcollect_run_missed_days = (integer) variable_get('recurring_globalcollect_run_missed_days', 0);
  
  if ($recurring_globalcollect_run_missed_days > 0) {

      $decrement_date = '-' . $recurring_globalcollect_run_missed_days . ' day';
      
      $start_day = date('Y-m-d 00:00:00', strtotime($decrement_date, $start_day_stamp));
  }
  
  $contribution_status_id = civicrm_api_contribution_status('Completed');
  
  $query  = '';
  $query .= 'SELECT `civicrm_contribution_recur`.* FROM `civicrm_contribution_recur` ';
  
  $where  = '';
  $where .= " WHERE `civicrm_contribution_recur`.`next_sched_contribution` BETWEEN '%s' AND '%s'";
  $where .= " AND `civicrm_contribution_recur`.`trxn_id` LIKE 'RECURRING GLOBALCOLLECT%'";
  $where .= " AND ( `civicrm_contribution_recur`.`end_date` IS NULL )";
  $where .= " AND `civicrm_contribution_recur`.`contribution_status_id` = %d";
  
  // Add on the WHERE statement
  $query .= $where;
  $query .= " LIMIT %d";

  $res = db_query( $query, $start_day, $end_day, $contribution_status_id, $limit );
  
  if ($limit > 1) {
    $out = array();
    while ($recur = db_fetch_object( $res ))
    {
      $out[] = $recur;
    }
  } else {
    $out = db_fetch_object( $res );
  }

  // Restore the default connection.
  $dbs->use_default();

  return $out;
}

/**
 * _recurring_globalcollect_get_next_sched_contribution_date_for_month
 * 
 * @return  string  Returns a date stamp in the format 'Y-m-d H:i:s' => 2011-12-31 00:00:00
 */
function _recurring_globalcollect_get_next_sched_contribution_date_for_month(&$record) {

  $triggered_for_date = _recurring_globalcollect_get_last_trigger_date($record);
  //$frequency_interval and $cycle_day will, at this point, have been found in $record. 
  $frequency_interval = (integer) $record['frequency_interval'];
  $cycle_day = $record['cycle_day'];

  $scheduled_date_stamp = $triggered_for_date;
  $added = 0;
  while ( date('Y-m-d', $triggered_for_date) >= date('Y-m-d', $scheduled_date_stamp) && ( $added < $frequency_interval ) ){
    //this will happen at least once.
    $scheduled_date_stamp = _recurring_globalcollect_increment_date_to_target_day( $scheduled_date_stamp, 'month', $cycle_day );
    $added += 1;
  }
  
  return date('Y-m-d H:i:s', $scheduled_date_stamp);
}

/**
 * Calculates the last date this payment should have been triggered for, 
 * regardless of the actual date, or the last recorded date in the schedule. 
 * @param array $record An array that contains, at least, the cycle day. Passing 
 * this around in record format because that's what the rest of the module does. 
 * @TODO: Stop passing around the whole record. 
 * @return timestamp A midnight timestamp for the day that should have triggered 
 * this recurring transaction. 
 */
function _recurring_globalcollect_get_last_trigger_date($record){
	//Instead of adding to now, we have to look for the last time the cycle date 
	//should have been triggered, regardless of when the transaction actually went through.
	
	//TODO: This needs to implement more frequency intervals. For now, though, we only use monthly, so...
	if ( !array_key_exists( 'cycle_day', $record ) || !is_numeric( $record['cycle_day'] ) ){
		return time();
	} else {
		$cycle_day = (integer) $record['cycle_day'];
	}
	
	$nowstamp = time();
	
	$month = (int) date('n', $nowstamp);
	$year = (int) date('Y', $nowstamp);
	
	$last_trigger = mktime(0, 0, 0, $month, _recurring_globalcollect_get_cycle_day_for_month( $cycle_day, $month, $year ), $year);
	
	//please note that the majority of the time, this while loop will not even 
	//fire once.  
	//The 60*60*24*7 represents a week in seconds: If what should have been the 
	//next trigger date is up to a week in the future, it will NOT try to rebill 
	//this month. 
	while ( $last_trigger > $nowstamp && ( $last_trigger - $nowstamp ) > 60*60*24*7 ){
		//decrement the month until it was in the past. 
		--$month;
		if ( $month < 1 ){
			$month = 12;
			--$year;
		}
		$last_trigger = mktime(0, 0, 0, $month, _recurring_globalcollect_get_cycle_day_for_month( $cycle_day, $month, $year ), $year);
	}
	return $last_trigger;
}



/**
 * Increment the $date by one $interval, landing as close as possible to 
 * $cycle_day. Have only implemented the $interval of 'month' at this point. 
 * Might wire up more later as-needed. 
 * @param timestamp $date Timestamp to increment by the interval
 * @param string $interval A name for the interval that we're incrementing. 
 * @param int $cycle_day The target day of the month for this payment
 * @return timestamp The $date parameter incremented by one calendar interval. 
 */
function _recurring_globalcollect_increment_date_to_target_day( $date, $interval = 'month', $cycle_day = null ){

  switch ($interval){ //just making it slightly nicer in here for the next guy
    case 'month':
    default:
      if ( is_null( $cycle_day ) ){
        $cycle_day = (int) date('j', $date);
      }
      $month = (int) date('n', $date);
      $year = (int) date('Y', $date);

      $month += 1;
      //if we wanted to edit this to handle adding more than one month at 
      //a time, we could do some fun stuff with modulo here. 
      if ( $month > 12 ){
        $month = 1;
        $year += 1;
      }

	  $target_day = _recurring_globalcollect_get_cycle_day_for_month($cycle_day, $month, $year);

      $next_date = mktime(0, 0, 0, $month, $target_day, $year);
      return $next_date;
  }
}

/**
 * Tired of doing this everywhere. 
 * @param int $cycle_day - target day of the month for this subscription
 * @param int $month - target month
 * @param int $year - target year. 
 * @return int The day of the specified month and year most appropriate for the 
 * target cycle day. This will only change if the target day doesn't exist in certain months. 
 */
function _recurring_globalcollect_get_cycle_day_for_month($cycle_day, $month, $year){
	$last_day = cal_days_in_month( CAL_GREGORIAN, $month, $year );
	if ( $cycle_day > $last_day ){
		return $last_day;
	}
	return $cycle_day;
}

/**
 * Select one recurring payment by `failure_retry_date`, that needs to be processed for the current day.
 * 
 * NOTE: `end_date` should only be set if the end has passed.
 * 
 * Example query called with standard options and the date set to: 2012-01-01
 * 
 * SELECT `civicrm_contribution_recur`.* FROM `civicrm_contribution_recur`
 *  WHERE `civicrm_contribution_recur`.`next_sched_contribution`
 *   BETWEEN '2012-01-01 00:00:00' AND '2012-04-01 23:59:59'
 *  AND `civicrm_contribution_recur`.`trxn_id` LIKE 'RECURRING GLOBALCOLLECT%'
 *  AND ( `civicrm_contribution_recur`.`end_date` IS NULL )
 *  AND `civicrm_contribution_recur`.`contribution_status_id` = 4
 * LIMIT 1
 * 
 * @param array   $options   Options
 * 
 * $options
 * - $date          string    Uses strtotime() to parse the date
 * - $limit         integer   Number of records to pull. Default is 1.
 * 
 * @todo The field `civicrm_payment_processor`.`payment_processor_type` should be set.
 * @todo Implement $contributionStatus = CRM_Contribute_PseudoConstant::contributionStatus( null, 'name' );
 * 
 * @return false|object
 */
function recurring_globalcollect_get_failure_retry_date($options = array()) {

  // make sure we're using the default (civicrm) db
  $dbs = wmf_civicrm_get_dbs();
  $dbs->use_civicrm();

  $date = isset( $options['date'] ) ? $options['date'] : 'now';

  // Search for dates with LIKE
  $date = date('Y-m-d', strtotime($date));

  $limit = isset($options['limit']) ? (integer) abs($options['limit']) : 1;

  $contribution_status_id = civicrm_api_contribution_status('Failed');
  
  $query  = '';
  $query .= 'SELECT `civicrm_contribution_recur`.* FROM `civicrm_contribution_recur` ';
  
  $where  = '';
  $where .= " WHERE `civicrm_contribution_recur`.`next_sched_contribution` BETWEEN '%s 00:00:00' AND '%s 23:59:59'";
  $where .= " AND `civicrm_contribution_recur`.`contribution_status_id` = %d";
  $where .= " AND `civicrm_contribution_recur`.`trxn_id` LIKE 'RECURRING GLOBALCOLLECT%'";
  $where .= " AND ( `civicrm_contribution_recur`.`end_date` IS NULL )";

  // Add on the WHERE statement
  $query .= $where;
  
  // Add a limit.
  if (!empty($limit)) {
    $query .= " LIMIT " . $limit;
  }
  
  $result = db_fetch_object( db_query( $query, $date, $date, $contribution_status_id ));

  // Restore the default connection.
  $dbs->use_default();

  // If nothing found, return false.
  if (empty($result)) {
    
    $result = false;
  }

  return $result;
}

/**
 * Select one subscription by the order id.
 *
 * This searches on the field: `civicrm_contribution_recur`.`trxn_id`
 *
 * This field has the format:
 *
 * RECURRING GLOBALCOLLECT <ORDERID> <TIMESTAMP>
 *
 * A LIKE statement is used to search on:
 * RECURRING GLOBALCOLLECT <ORDERID> %
 *
 * This ignores the timestamp when doing a search.
 *
 * @param integer $order_id must be a positive integer.
 * @return false|array
 */
function _recurring_globalcollect_get_subscription_by_order_id($order_id) {

  // Only return records if an order_id is given.
  if ( empty( $order_id ) ) {
    return false;
  }
  
  // make sure we're using the default (civicrm) db
  $dbs = wmf_civicrm_get_dbs();
  $dbs->use_civicrm();

  $query  = 'SELECT `civicrm_contribution_recur`.*, `civicrm_contact`.`display_name` FROM `civicrm_contribution_recur`';
  $query .= ' LEFT JOIN `civicrm_contact` ON (`civicrm_contribution_recur`.`contact_id` = `civicrm_contact`.`id`)';
  $query .= ' WHERE `civicrm_contribution_recur`.`trxn_id` LIKE ';
  $query .="'RECURRING GLOBALCOLLECT %d%' LIMIT 1";
  
  $result = db_fetch_object( db_query( $query, $order_id ) );

  // Restore the default connection.
  $dbs->use_default();

  $record = is_object( $result ) ? (array) $result : false;
  
  return $record;
}

/**
 * Update recurring payment for failure.
 *
 * There are two different queries in this function.
 * - payments need to be marked as failure
 * - payments need to be marked as cancelled if there have been two prior failures for this EFFORTID (`processor_id`)
 *
 * These are the possible values for `contribution_status_id`:
 * - [1] => Completed
 * - [2] => Pending
 * - [3] => Cancelled
 * - [4] => Failed
 * - [5] => In Progress
 * - [6] => Overdue
 *
 * @param integer $id     The primary key of the record.
 * 
 * @return integer  Returns the number of affected rows.
 */
function _recurring_globalcollect_update_record_failure($id) {

  // make sure we're using the default (civicrm) db
  $dbs = wmf_civicrm_get_dbs();
 
  $result = recurring_globalcollect_get_payment_by_id($id);
  $record = is_object($result) ? (array) $result : false;
  
  if (empty($record)) {
    $message = 'No record was found with the id: [' . $id . '].';
    throw new RecurringGlobalcollectInternalException($message);
  }

  // Make sure all of the proper fields are set to sane values.
  _recurring_globalcollect_validate_record_for_update($record);
  
  $recurring_globalcollect_failure_retry_rate = (integer) abs(variable_get('recurring_globalcollect_failure_retry_rate', 1));

  $cancel = false;
  $contribution_status_id = civicrm_api_contribution_status('Failed');
  $failures_before_cancellation = (integer) variable_get('recurring_globalcollect_failures_before_cancellation', 0);

  // If there have been too many failures, cancel this payment permanently.
  if ( $record['failure_count'] >= ( $failures_before_cancellation - 1 ) ) {
    $contribution_status_id = civicrm_api_contribution_status('Cancelled');
    $end_date = 'NULL';
    $failure_retry_date = 'NULL';
    $next_sched_contribution = 'NULL';
    $cancel = true;
  }

  $dbs->use_civicrm();

  $query = '';
  if ($cancel) {
    // The payment is being cancelled
    $query .= 'UPDATE {civicrm_contribution_recur} SET';
    $query .= ' `failure_count` = `failure_count` + 1,';
    $query .= ' `failure_retry_date` = NULL,';
    $query .= ' `cancel_date` = NOW(),';
    $query .= ' `contribution_status_id` = %d,';
    $query .= ' `next_sched_contribution` = NULL';
    $query .= ' WHERE id = %d';
    db_query($query, $recurring_globalcollect_failure_retry_rate, $contribution_status_id, $id);
  }
  else {
    // The payment failed and is being marked as a failure.
    $query .= 'UPDATE {civicrm_contribution_recur} SET';
    $query .= ' `contribution_status_id` = %d,';
    $query .= ' `failure_count` = `failure_count` + 1,';
    $query .= ' `failure_retry_date` = NOW() + INTERVAL %d DAY';
    $query .= ' WHERE id = %d';
    db_query($query, $contribution_status_id, $recurring_globalcollect_failure_retry_rate, $id);
  }

  $affected_rows = (integer) db_affected_rows();
  
  // Restore the default connection.
  $dbs->use_default();
  
  return $affected_rows;
}

/**
 * Update recurring payment as in progress.
 *
 * @todo Implement $contributionStatus = CRM_Contribute_PseudoConstant::contributionStatus( null, 'name' );
 *
 * @param integer $id     The primary key of the record.
 * 
 * @return integer  Returns the number of affected rows.
 */
function _recurring_globalcollect_update_record_in_progress($id) {

  // make sure we're using the default (civicrm) db
  $dbs = wmf_civicrm_get_dbs();
 
  $result = recurring_globalcollect_get_payment_by_id($id);
  $record = is_object($result) ? (array) $result : false;
  
  if (empty($record)) {
    $message = 'No record was found with the id: [' . $id . '].';
    throw new RecurringGlobalcollectInternalException($message);
  }
  
  if ($record['contribution_status_id'] != 1) {
    $message = 'The subscription is supposed to be in a completed state before it can be processed: ' . print_r( $record, true );
    watchdog('recurring_globalcollect', $message, null, WATCHDOG_ERROR);
    drush_set_error('RG', $message);
    return false;
  }

  $query = '';
  // The payment is being marked as in progress.
  $query .= 'UPDATE {civicrm_contribution_recur} SET';
  // Set the status to In Progress
  $query .= ' `contribution_status_id` = %d';
  $query .= ' WHERE id = %d';
  // Only update if status is Completed
  $query .= ' AND `contribution_status_id` != %d';
  $dbs->use_civicrm();
  db_query($query, civicrm_api_contribution_status('In Progress'), $id, civicrm_api_contribution_status('In Progress'));

  $affected_rows = (integer) db_affected_rows();
  
  // Restore the default connection.
  $dbs->use_default();

  if ($affected_rows == 0) {
    $message = 'The subscription was not marked as in progress: ' . print_r( $record, true );
    watchdog('recurring_globalcollect', $message, null, WATCHDOG_ERROR);
    drush_set_error('RG', $message);
    return false;
  }
  
  return $affected_rows;
}

/**
 * Update recurring payment for success.
 *
 * Payments will be rescheduled for the following month.
 *
 * @param integer $id     The primary key of the record.
 * 
 * @return integer  Returns the number of affected rows.
 */
function _recurring_globalcollect_update_record_success($id) {

  // make sure we're using the default (civicrm) db
  $dbs = wmf_civicrm_get_dbs();
 
  $result = recurring_globalcollect_get_payment_by_id($id);
  $record = is_object($result) ? (array) $result : false;
  
  if (empty($record)) {
    $message = 'No record was found with the id: [' . $id . '].';
    throw new RecurringGlobalcollectInternalException($message);
  }

  // Make sure all of the proper fields are set to sane values.
  _recurring_globalcollect_validate_record_for_update($record);
  
  $next_sched_contribution = _recurring_globalcollect_get_next_sched_contribution_date_for_month($record);

  $query = '';
  // The payment is being scheduled for the next date.
  $query .= 'UPDATE {civicrm_contribution_recur} SET';
  $query .= ' `failure_count` = 0,';
  $query .= ' `failure_retry_date` = NULL,';
  $query .= ' `processor_id` = `processor_id` + 1,';
  $query .= ' `contribution_status_id` = %d,';
  $query .= " `next_sched_contribution` = '%s'";
  $query .= ' WHERE id = %d';
  $dbs->use_civicrm();
  db_query($query, civicrm_api_contribution_status('Completed'), $next_sched_contribution, $id);

  $affected_rows = (integer) db_affected_rows();
  
  // Restore the default connection.
  $dbs->use_default();
  
  return $affected_rows;
}

/**
 * _recurring_globalcollect_validate_record_for_update
 * 
 * @param array $record
 * @throws Exception 
 * @return boolean
 */
function _recurring_globalcollect_validate_record_for_update($record) {

  // Allowed intervals for incrementing the next contribution date.
  $allowed_intervals = array(
      //'day',
      //'week',
      'month',
      //'year',
  );

  $cycle_day = isset($record['cycle_day']) ? (integer) $record['cycle_day'] : false;
  $frequency_unit = isset($record['frequency_unit']) ? $record['frequency_unit'] : false;
  $frequency_interval = (integer) $record['frequency_interval'];
  $next_sched_contribution = isset($record['next_sched_contribution']) ? $record['next_sched_contribution'] : false;

  // Make sure $cycle_day is not empty
  if (empty($cycle_day)) {
    $message = 'cycle_day cannot be empty';
    throw new RecurringGlobalcollectInternalException( $message );
  }

  // Make sure $frequency_interval is not empty
  if (empty($frequency_interval)) {
    $message = 'frequency_interval cannot be empty';
    throw new RecurringGlobalcollectInternalException( $message );
  }

  // Make sure a valid interval is assigned
  if (!in_array($frequency_unit, $allowed_intervals)) {
    $message = 'Invalid frequency_unit [' . $frequency_unit . '] for recurring_globalcollect. Allowed intervals: [ ' . implode(', ', $allowed_intervals) . ' ]';
    throw new RecurringGlobalcollectInternalException( $message );
  }

  // Make sure $next_sched_contribution is assigned
  if (empty($next_sched_contribution)) {
    $message = 'next_sched_contribution cannot be empty';
    throw new RecurringGlobalcollectInternalException( $message );
  }
}

/**
 * Will return all the new drush errors since the last time you asked.
 *
 * @todo Move this to the wikimedia common module
 */
function _recurring_globalcollect_get_new_drush_errors(){
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

/**
 * Build the default test Url
 *
 * @return string  Returns the default testing url
 */
function buildDefaultTestUrl() {

  $protocol = (isset($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) == 'on') ? 'https' : 'http';
  $host = (isset($_SERVER['HTTP_HOST'])) ? $_SERVER['HTTP_HOST'] : 'localhost';
  
  $defaultTestUrl = $protocol . '://' . $host . '/';

  return $defaultTestUrl;
}

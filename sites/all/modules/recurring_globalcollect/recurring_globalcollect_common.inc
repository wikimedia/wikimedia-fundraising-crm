<?php 
/**
 * Common functions for recurring_globalcollect modules 
 */

/**
 * @see _queue2civicrm_get_dbs
 */
require_once( drupal_get_path( 'module', 'queue2civicrm' ) . '/queue2civicrm_common.inc' );

/**
 * Get the path of the file to make sure it is installed.
 *
 * @var string $standalone_globalcollect_adapter_path
 */
$standalone_globalcollect_adapter_path = variable_get('standalone_globalcollect_adapter_path', drupal_get_path('module', 'recurring_globalcollect') . '/library/StandaloneGlobalCollectAdapter.php');

// If $standalone_globalcollect_adapter_path is a valid file, require the class.
if ( is_file( $standalone_globalcollect_adapter_path ) ) {
  require_once $standalone_globalcollect_adapter_path;
}

/**
 * Wrapper for getting contributions
 *
 * @return  array  Returns an array of options for @see _recurring_globalcollect_get_next_sched_contribution() and @see _recurring_globalcollect_get_failure_retry_date()
 */
function recurring_globalcollect_get($options = array()) {

  /**
   * @var array $allowed_processes These are the allowed process to run.
   */
  $allowed_processes = array(
      'next_sched_contribution',
      'failure_retry_date',
  );

  if (isset($options['id'])) {
    // fetch by id
    return _recurring_globalcollect_get_payment_by_id($options['id']);
  }
  elseif ( isset($options['process']) && $options['process'] == 'next_sched_contribution' ) {
    return _recurring_globalcollect_get_next_sched_contribution($options);
  }
  elseif ( isset($options['process']) && $options['process'] == 'failure_retry_date' ) {
    return _recurring_globalcollect_get_failure_retry_date($options);
  }
  else {
  	$message = 'What are you trying process? $options: <pre>' . print_r($options, true) . '</pre>';
    watchdog('rg', $message, null, WATCHDOG_ERROR);
    drush_set_error('RG', $message);
 	return false;
  }
}

/**
 * Select one payment by the primary key.
 * 
 * @return false|object
 */
function _recurring_globalcollect_get_payment_by_id($id) {

  // make sure we're using the default (civicrm) db
  $dbs = _queue2civicrm_get_dbs();
  $dbs->use_civicrm();

  $query = 'SELECT `civicrm_contribution_recur`.* FROM `civicrm_contribution_recur` WHERE `civicrm_contribution_recur`.`id` = %d LIMIT 1';
  
  //echo "\n" . $query . "\n";
  $result = db_fetch_object( db_query( $query, $id ));

  // If nothing found, return false.
  if (empty($result)) {
    
    $result = false;
  }

  // Restore the database connection.
  $dbs->use_default();
 
  return $result;
}

/**
 * Select one recurring payment by `next_sched_contribution`, that needs to be processed for the current day.
 * 
 * SELECT `civicrm_contribution_recur`.* FROM `civicrm_contribution_recur`
 * WHERE ( `civicrm_contribution_recur`.`end_date` = '0000-00-00 00:00:00' OR `civicrm_contribution_recur`.`end_date` IS NULL )
 * AND ( `civicrm_contribution_recur`.`failure_retry_date` = '0000-00-00 00:00:00' OR `civicrm_contribution_recur`.`failure_retry_date` IS NULL )
 * AND `civicrm_contribution_recur`.`failure_count` = 0
 * AND `civicrm_contribution_recur`.`next_sched_contribution` BETWEEN '2011-12-28 00:00:00' AND  '2011-12-31 23:59:59'
 * AND `civicrm_contribution_recur`.`trxn_id` LIKE 'RECURRING GLOBALCOLLECT%'
 * 
 * @param array   $options   Options
 * 
 * $options
 * - date           string    Uses strtotime() to parse the date
 * - $limit         integer   Number of records to pull. Default is 1.
 * 
 * @todo `civicrm_payment_processor`.`payment_processor_type` might need to be specified in settings.
 * 
 * @todo Should query use LIKE on date searches? `civicrm_contribution_recur`.`next_sched_contribution` LIKE '2012-01-01%' - There could be a lot of records in this table. This would require a bench mark test.
 * 
 * @return false|object
 */
function _recurring_globalcollect_get_next_sched_contribution($options = array()) {

  // make sure we're using the default (civicrm) db
  $dbs = _queue2civicrm_get_dbs();
  $dbs->use_civicrm();

  $date = isset( $options['date'] ) ? $options['date'] : 'now';
  //echo "\n \$date: \n" . print_r($date, true) . "\n";

  // Get the date
  $date = date('Y-m-d', strtotime($date));
  
  $start_day = $date . ' 00:00:00';
  //echo "\n \$start_day: \n" . print_r($start_day, true) . "\n";
  $end_day = $date . ' 23:59:59';
  //echo "\n \$end_day: \n" . print_r($end_day, true) . "\n";
  
  $start_day_stamp = strtotime($start_day);
  $end_day_stamp = strtotime($end_day);

  $recurring_globalcollect_run_missed_days = (integer) variable_get('recurring_globalcollect_run_missed_days', 0);
  //echo "\n \$recurring_globalcollect_run_missed_days: \n" . print_r($recurring_globalcollect_run_missed_days, true) . "\n";
  
  if ($recurring_globalcollect_run_missed_days > 0) {

      $decrement_date = '-' . $recurring_globalcollect_run_missed_days . ' day';
      //echo "\n \$decrement_date: \n" . print_r($decrement_date, true) . "\n";
      
      $start_day = date('Y-m-d 00:00:00', strtotime($decrement_date, $start_day_stamp));
      //echo "\n \$start_day: \n" . print_r($start_day, true) . "\n";
  }
  
  $limit = isset($options['limit']) ? (integer) abs($options['limit']) : 1;
  
  $query  = '';
  $query .= 'SELECT `civicrm_contribution_recur`.* FROM `civicrm_contribution_recur` ';
  
  $where  = '';
  $where .= " WHERE ( `civicrm_contribution_recur`.`end_date` = '0000-00-00 00:00:00' OR `civicrm_contribution_recur`.`end_date` IS NULL )";
  $where .= " AND ( `civicrm_contribution_recur`.`failure_retry_date` = '0000-00-00 00:00:00' OR `civicrm_contribution_recur`.`failure_retry_date` IS NULL )";
  $where .= " AND `civicrm_contribution_recur`.`failure_count` = 0 ";
  $where .= " AND `civicrm_contribution_recur`.`next_sched_contribution` BETWEEN '%s' AND '%s'";
  $where .= " AND `civicrm_contribution_recur`.`trxn_id` LIKE 'RECURRING GLOBALCOLLECT%'";

  
  // Add on the WHERE statement
  $query .= $where;
  
  // Add a limit.
  if (!empty($limit)) {
    $query .= " LIMIT %d";
	$res = db_query( $query, $start_day, $end_day, $limit );
  } else {
	$res = db_query( $query, $start_day, $end_day );
  }
  
  //echo "\n" . $query . "\n";
  $result = db_fetch_object( $res );

  // If nothing found, return false.
  if (empty($result)) {
    
    $result = false;
  }

  // Restore the database connection.
  $dbs->use_default();
 
  return $result;
}

/**
 * _recurring_globalcollect_get_next_sched_contribution_date_for_month
 * 
 * @return  string  Returns a date stamp in the format 'Y-m-d H:i:s' => 2011-12-31 00:00:00
 */
function _recurring_globalcollect_get_next_sched_contribution_date_for_month(&$record) {

  //echo "\$record" . print_r($record, true) . "\n";
  $now_stamp = time();
  //$frequency_interval and $cycle_day will, at this point, have been found in $record. 
  $frequency_interval = (integer) $record['frequency_interval'];
  $cycle_day = $record['cycle_day'];

  $scheduled_date_stamp = $now_stamp;
  $added = 0;
  while ( date('Y-m-d', $now_stamp) >= date('Y-m-d', $scheduled_date_stamp) && ( $added < $frequency_interval ) ){
	  //this will happen at least once.
	  $scheduled_date_stamp = _recurring_globalcollect_increment_date_to_target_day( $scheduled_date_stamp, 'month', $cycle_day );
	  $added += 1;
  }
  
  return date('Y-m-d H:i:s', $scheduled_date_stamp);
}


//Increment the $date by one $interval, landing as close as possible to $cycle_day.
//have only implemented the $interval of 'month' at this point. Might wire up more later as-needed. 
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

			$last_day = cal_days_in_month( CAL_GREGORIAN, $month, $year );

			if ( $cycle_day > $last_day ){
				$cycle_day = $last_day;
			}

			$next_date = mktime(0, 0, 0, $month, $cycle_day, $year);
			//echo "$month/$cycle_day/$year = $next_date\n";
			return $next_date;
	}
}

/**
 * Select one recurring payment by `failure_retry_date`, that needs to be processed for the current day.
 * 
 * NOTE: `end_date` should only be set if the end has passed.
 * 
 * SELECT `civicrm_contribution_recur`.* FROM `civicrm_contribution_recur`  
 * LEFT JOIN `civicrm_payment_processor` ON ( `civicrm_payment_processor`.`id` = `civicrm_contribution_recur`.`payment_processor_id` ) 
 * WHERE ( `civicrm_contribution_recur`.`end_date` = '0000-00-00 00:00:00' OR `civicrm_contribution_recur`.`end_date` IS NULL ) 
 * AND `civicrm_contribution_recur`.`failure_retry_date` LIKE '2012-02-10%' AND `civicrm_contribution_recur`.`failure_count` < 3 
 * AND `civicrm_payment_processor`.`payment_processor_type`  = 'GlobalCollect' LIMIT 1
 * 
 * @param array   $options   Options
 * 
 * $options
 * - date           string    Uses strtotime() to parse the date
 * - failure_count  integer   Number of failures to allow before we stop processing. Default is 3.
 * - $limit         integer   Number of records to pull. Default is 1.
 * 
 * @todo `civicrm_payment_processor`.`payment_processor_type` might need to be specified in settings.
 * 
 * @return false|object
 */
function _recurring_globalcollect_get_failure_retry_date($options = array()) {

  // make sure we're using the default (civicrm) db
  $dbs = _queue2civicrm_get_dbs();
  $dbs->use_civicrm();

  $date = isset( $options['date'] ) ? $options['date'] : 'now';
  //echo "\n \$date: \n" . print_r($date, true) . "\n";

  // Search for dates with LIKE
  $date = date('Y-m-d', strtotime($date));

  $limit = isset($options['limit']) ? (integer) abs($options['limit']) : 1;
  $failure_count = isset($options['failure_count']) ? (integer) abs($options['failure_count']) : 3;
  
  $query  = '';
  $query .= 'SELECT `civicrm_contribution_recur`.* FROM `civicrm_contribution_recur` ';
  
  $where  = '';
  $where .= " WHERE ( `civicrm_contribution_recur`.`end_date` = '0000-00-00 00:00:00' OR `civicrm_contribution_recur`.`end_date` IS NULL )";
  $where .= " AND `civicrm_contribution_recur`.`failure_retry_date` BETWEEN '%s 00:00:00' AND  '%s 23:59:59'";
  $where .= " AND `civicrm_contribution_recur`.`failure_count` < %d";
  $where .= " AND `civicrm_contribution_recur`.`trxn_id` LIKE 'RECURRING GLOBALCOLLECT%'";
  
  // Add on the WHERE statement
  $query .= $where;
  
  // Add a limit.
  if (!empty($limit)) {
    $query .= " LIMIT " . $limit;
  }
  
  //echo "\n" . $query . "\n";
  $result = db_fetch_object( db_query( $query, $date, $date, $failure_count ));

  // If nothing found, return false.
  if (empty($result)) {
    
    $result = false;
  }

  // Restore the database connection.
  $dbs->use_default();
 
  return $result;
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
  $dbs = _queue2civicrm_get_dbs();
  $dbs->use_civicrm();
 
  $result = _recurring_globalcollect_get_payment_by_id($id);
  $record = is_object($result) ? (array) $result : false;
  
  if (empty($record)) {
    $message = 'No record was found with the id: [' . $id . '].';
    throw new Exception($message);
  }

  // Make sure all of the proper fields are set to sane values.
  _recurring_globalcollect_validate_record_for_update($record);
  
  $recurring_globalcollect_failure_retry_rate = (integer) abs(variable_get('recurring_globalcollect_failure_retry_rate', 1));

  // Flag which db_query() to use
  $cancel = false;
  $contribution_status_id = 4;
  
  // If there have been too many failures, cancel this payment permanently.
  if ( $record['failure_count'] >= 2 ) {
    $contribution_status_id = 3;
    $end_date = 'NULL';
    $failure_retry_date = 'NULL';
    $next_sched_contribution = 'NULL';
    $cancel = true;
  }

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
    //die( eval('return  __FILE__ . date(\'r\') . "\n";') . "\$query" . print_r($query, true) . "\n" );
    db_query($query, $recurring_globalcollect_failure_retry_rate, $contribution_status_id, $id);
  }
  else {
  	// The payment failed and is being marked as a failure.
    $query .= 'UPDATE {civicrm_contribution_recur} SET';
    $query .= ' `contribution_status_id` = %d,';
    $query .= ' `failure_count` = `failure_count` + 1,';
    $query .= ' `failure_retry_date` = NOW() + INTERVAL %d DAY';
    $query .= ' WHERE id = %d';
    //die( eval('return  __FILE__ . date(\'r\') . "\n";') . "\$query" . print_r($query, true) . "\n" );
    db_query($query, $contribution_status_id, $recurring_globalcollect_failure_retry_rate, $id);
  }

  $affected_rows = (integer) db_affected_rows();
  
  // Restore the database connection.
  $dbs->use_default();
  
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
  $dbs = _queue2civicrm_get_dbs();
  $dbs->use_civicrm();
 
  $result = _recurring_globalcollect_get_payment_by_id($id);
  $record = is_object($result) ? (array) $result : false;
  
  if (empty($record)) {
    $message = 'No record was found with the id: [' . $id . '].';
    throw new Exception($message);
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
  $query .= ' `contribution_status_id` = 1,';
  $query .= " `next_sched_contribution` = '%s'";
  $query .= ' WHERE id = %d';
  //die( eval('return  __FILE__ . date(\'r\') . "\n";') . "\$query" . print_r($query, true) . "\n" );
  db_query($query, $next_sched_contribution, $id);

  $affected_rows = (integer) db_affected_rows();
  
  // Restore the database connection.
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
    throw new Exception( $message );
  }

  // Make sure $frequency_interval is not empty
  if (empty($frequency_interval)) {
    $message = 'frequency_interval cannot be empty';
    throw new Exception( $message );
  }

  // Make sure a valid interval is assigned
  if (!in_array($frequency_unit, $allowed_intervals)) {
    $message = 'Invalid frequency_unit [' . $frequency_unit . '] for recurring_globalcollect. Allowed intervals: [ ' . implode(', ', $allowed_intervals) . ' ]';
    throw new Exception( $message );
  }

  // Make sure $next_sched_contribution is assigned
  if (empty($next_sched_contribution)) {
    $message = 'next_sched_contribution cannot be empty';
    throw new Exception( $message );
  }
}

/**
 * Will return all the new drush errors since the last time you asked.
 *
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
 * Will return the most serious of all the new drush errors since the last time you asked,
 * with all its new lines intact.
 *
 * @uses _recurring_globalcollect_get_new_drush_errors()
 */
function _recurring_globalcollect_get_top_new_drush_error(){

  $error = _recurring_globalcollect_get_new_drush_errors();

  /**
   *  @var  array  $severity  The severity error levels 
   *
   * $severity
   * - CIVI_CONFIG: A problem with CiviCRM
   * - RG: A problem with recurring_globalcollect
   * - FUTURE_DATE: A future date was set.
   * - GLOBALCOLLECT: A problem with GLOBALCOLLECT
   * - CIVI_INSERT: Failed inserting a completed contribution into civi. 
   * - UNKNOWN: A unknown problem
   */
  $severity = array(
    'CIVI_CONFIG',
    'RG',
    'GLOBALCOLLECT',
    'FUTURE_DATE',
	'CIVI_INSERT',
    'UNKNOWN',
  );
  
  if (empty($error)){
    return false;
  } else {  //pull the Top of Bad.
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

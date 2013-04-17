<?php 
/**
 * Common functions for recurring_globalcollect modules 
 */


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
  $dbs->push( 'civicrm' );

  $query = 'SELECT `civicrm_contribution_recur`.* FROM `civicrm_contribution_recur` WHERE `civicrm_contribution_recur`.`id` = :id LIMIT 1';
  
  $result = db_query( $query, array( ':id' => $id ) )->fetch();

  if ( empty( $result ) ) {
    throw new WmfException( 'INVALID_RECURRING', t( 'No record was found with the id: [!id].', array( '!id' => $id ) ) );
  }
 
  return $result;
}

/**
 * Select a set of recurring payments that need to be retried today
 * 
 * NOTE: `end_date` should only be set if the end has passed.
 * 
 * Example query called with standard options and the date set to: 2012-01-01
 * 
 * SELECT `civicrm_contribution_recur`.* FROM `civicrm_contribution_recur`
 *  WHERE `civicrm_contribution_recur`.`failure_retry_date`
 *   BETWEEN '2012-01-01 00:00:00' AND '2012-04-01 23:59:59'
 *  AND `civicrm_contribution_recur`.`trxn_id` LIKE 'RECURRING GLOBALCOLLECT%'
 *  AND ( `civicrm_contribution_recur`.`end_date` IS NULL )
 *  AND `civicrm_contribution_recur`.`contribution_status_id` = 4
 * LIMIT 1
 * 
 * @param int $limit Number of records to pull. Default is 1.
 * @param string $date Uses strtotime() to parse the date
 *
 * @todo The field `civicrm_payment_processor`.`payment_processor_type` should be set.
 * @todo Implement $contributionStatus = CRM_Contribute_PseudoConstant::contributionStatus( null, 'name' );
 * 
 * @return false|object
 */
function recurring_globalcollect_get_failure_retry_batch($limit = 1, $date = 'now') {

  // make sure we're using the default (civicrm) db
  $dbs = wmf_civicrm_get_dbs();
  $dbs->push( 'civicrm' );

  $date = date('Y-m-d', strtotime($date));

  $past_days = variable_get('recurring_globalcollect_run_missed_days', 0);
  $start_date = new DateTime($date);
  $start_date = $start_date->sub(date_interval_create_from_date_string("$past_days days"));
  $start_date = $start_date->format('Y-m-d');

  $contribution_status_id = civicrm_api_contribution_status('Failed');
  
  $query = <<<EOS
SELECT civicrm_contribution_recur.* FROM civicrm_contribution_recur
WHERE
    civicrm_contribution_recur.failure_retry_date BETWEEN :start AND :now
    AND civicrm_contribution_recur.contribution_status_id = :failed_status
    AND civicrm_contribution_recur.trxn_id LIKE 'RECURRING GLOBALCOLLECT%'
    AND ( civicrm_contribution_recur.end_date IS NULL )
EOS;

  // Add on the WHERE statement
  $query .= $where;
  
  // Add a limit.
  if ($limit > 0) {
    $query .= " LIMIT " . $limit;
  }

  $result = db_query( $query, array(
    ':start' => "{$start_date} 00:00:00",
    ':now' => "{$date} 23:59:59",
    ':failed_status' => $contribution_status_id,
  ) );

  $out = array();
  foreach ( $result as $row ) {
      $out[] = $row;
  }

  return $out;
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
  $order_id = intval( $order_id );
  
  // make sure we're using the default (civicrm) db
  $dbs = wmf_civicrm_get_dbs();
  $dbs->push( 'civicrm' );

  $query = <<<EOS
SELECT
    civicrm_contribution_recur.*, civicrm_contact.display_name
FROM civicrm_contribution_recur
LEFT JOIN civicrm_contact ON
    civicrm_contribution_recur.contact_id = civicrm_contact.id
WHERE civicrm_contribution_recur.trxn_id LIKE 'RECURRING GLOBALCOLLECT :order_id %' LIMIT 1
EOS;
  
  $result = db_query( $query, array( ':order_id' => $order_id ) )->fetch();

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
 * XXX actually the ids are determined by querying the db
 * - [1] => Completed (previous donation succeeded, or new donation that has never been recurred before)
 * - [2] => Pending (not used by this module)
 * - [3] => Cancelled (too many failures in the past, don't try to process this any more)
 * - [4] => Failed (previous donation attempt failed, retry later)
 * - [5] => In Progress (there is a process actively trying to process this donation right now; used to avoid race conditions, if a contribution is stuck in this state it'll need manual intervention and reconciliation)
 * - [6] => Overdue (not used by this module)
 *
 * @param integer $id     The primary key of the record.
 * 
 * @return integer  Returns the number of affected rows.
 */
function _recurring_globalcollect_update_record_failure($id) {

  $result = recurring_globalcollect_get_payment_by_id($id);
  $record = (array) $result;

  // Make sure all of the proper fields are set to sane values.
  _recurring_globalcollect_validate_record_for_update($record);
  
  $failures_before_cancellation = (integer) variable_get( 'recurring_globalcollect_failures_before_cancellation', 0 );
  $recurring_globalcollect_failure_retry_rate = (integer) abs(variable_get('recurring_globalcollect_failure_retry_rate', 1));

  // make sure we're using the default (civicrm) db
  $dbs = wmf_civicrm_get_dbs();
  $dbs->push( 'civicrm' );

  $cancel = false;
  $contribution_status_id = civicrm_api_contribution_status('Failed');

  // If there have been too many failures, cancel this payment permanently.
  if ( $record['failure_count'] >= ( $failures_before_cancellation - 1 ) ) {
    $contribution_status_id = civicrm_api_contribution_status('Cancelled');
    $end_date = 'NULL';
    $failure_retry_date = 'NULL';
    $next_sched_contribution = 'NULL';
    $cancel = true;
    // TODO should we report the fact that we're cancelling this payment forever ("marking it as dead")?
  }

  if ($cancel) {
    // The payment is being cancelled
    $affected_rows = db_update( 'civicrm_contribution_recur' )
        ->expression( 'failure_count', "failure_count + 1" )
        ->expression( 'cancel_date', "NOW()" )
        ->fields( array(
            'failure_retry_date' => null,
            'contribution_status_id' => $contribution_status_id,
            'next_sched_contribution' => null,
        ) )->condition( 'id', $id )->execute();
  }
  else {
    // The payment failed and is being marked as a failure.
    $affected_rows = db_update( 'civicrm_contribution_recur' )
        ->expression( 'failure_count', "failure_count + 1" )
        ->expression( 'failure_retry_date', "NOW() + INTERVAL {$recurring_globalcollect_failure_retry_rate} DAY" )
        ->fields( array(
            'contribution_status_id' => $contribution_status_id,
        ) )->condition( 'id', $id )->execute();
  }

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
 
  $result = recurring_globalcollect_get_payment_by_id($id);
  $record = (array) $result;
  
  $working_statuses = array(
      civicrm_api_contribution_status( 'Completed' ),
      civicrm_api_contribution_status( 'Failed' ),
  );
  if ( !in_array( $record['contribution_status_id'], $working_statuses ) ) {
    throw new WmfException( 'INVALID_RECURRING', t( 'The subscription is supposed to be in a completed or failed state before it can be processed.' ), $record );
  }

  $dbs = wmf_civicrm_get_dbs();
  $dbs->push( 'civicrm' );

  $in_progress_id = civicrm_api_contribution_status('In Progress');
  $affected_rows = db_update( 'civicrm_contribution_recur' )
      ->fields( array(
          'contribution_status_id' => $in_progress_id,
      ) )
      ->condition( 'id', $id )
      ->execute();

  $dbs->pop();
  
  if ( !$affected_rows ) {
    throw new WmfException( 'INVALID_RECURRING', t( 'The subscription was not marked as in progress.' ), $record );
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
 
  $result = recurring_globalcollect_get_payment_by_id($id);
  $record = (array) $result;
  
  // Make sure all of the proper fields are set to sane values.
  _recurring_globalcollect_validate_record_for_update($record);
  
  $next_sched_contribution = wmf_civicrm_get_next_sched_contribution_date_for_month($record);

  $dbs = wmf_civicrm_get_dbs();
  $dbs->push( 'civicrm' );

  $affected_rows = db_update( 'civicrm_contribution_recur' )
      ->fields( array(
          'failure_count' => 0,
          'failure_retry_date' => null,
          'contribution_status_id' => civicrm_api_contribution_status('Completed'),
          'next_sched_contribution' => $next_sched_contribution,
      ) )
      ->expression( 'processor_id', "processor_id + 1" )
      ->condition( 'id', $id )
      ->execute();

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
    throw new WmfException( 'INVALID_RECURRING', $message, $record );
  }

  // Make sure $frequency_interval is not empty
  if (empty($frequency_interval)) {
    $message = 'frequency_interval cannot be empty';
    throw new WmfException( 'INVALID_RECURRING', $message, $record );
  }

  // Make sure a valid interval is assigned
  if (!in_array($frequency_unit, $allowed_intervals)) {
    $message = 'Invalid frequency_unit [' . $frequency_unit . '] for recurring_globalcollect. Allowed intervals: [ ' . implode(', ', $allowed_intervals) . ' ]';
    throw new WmfException( 'INVALID_RECURRING', $message, $record );
  }

  // Make sure $next_sched_contribution is assigned
  if (empty($next_sched_contribution)) {
    $message = 'next_sched_contribution cannot be empty';
    throw new WmfException( 'INVALID_RECURRING', $message, $record );
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

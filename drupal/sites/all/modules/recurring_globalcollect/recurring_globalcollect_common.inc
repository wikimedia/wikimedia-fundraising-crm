<?php
/**
 * Common functions for recurring_globalcollect modules
 */

use Civi\Api4\ContributionRecur;
use Civi\WMFException\WMFException;

/**
 * Select one payment by the primary key.
 *
 * @param int $id
 *
 * @return array
 * @throws \API_Exception
 * @throws \Civi\API\Exception\UnauthorizedException
 */
function recurring_globalcollect_get_payment_by_id($id) {
  return (array) ContributionRecur::get()
    ->setCheckPermissions(FALSE)
    ->addWhere('id', '=', (int) $id)
    ->addSelect('*')
    ->execute()->first();
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
 * @param string $date End of period to look for failure retries. Start of
 *      period is this minus recurring_globalcollect_run_missed_days. Uses
 *      strtotime() to parse the date.
 *
 * @todo The field `civicrm_payment_processor`.`payment_processor_type` should be set.
 * @todo Implement $contributionStatus = CRM_Contribute_PseudoConstant::contributionStatus( null, 'name' );
 *
 * @return false|object
 */
function recurring_globalcollect_get_failure_retry_batch($limit = 1, $date = 'now', $past_days = 0) {

  // make sure we're using the default (civicrm) db
  $dbs = wmf_civicrm_get_dbs();
  $dbs->push( 'civicrm' );

  $oldTimezone = date_default_timezone_get();
  date_default_timezone_set( "UTC" );

  $date = date('Y-m-d', strtotime($date));
  $start_date = new DateTime($date);
  $start_date = $start_date->sub(date_interval_create_from_date_string("$past_days days"));
  $start_date = $start_date->format('Y-m-d');

  date_default_timezone_set( $oldTimezone );

  $failingStatusID = CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_ContributionRecur', 'contribution_status_id', 'Failing');
  // We will also handle Failed temporarily.
  $failedStatusID = CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_ContributionRecur', 'contribution_status_id', 'Failed');

  watchdog(
    'recurring_globalcollect',
    'Looking for failed contributions in timeframe @min 00:00:00 -> @max 23:59:59',
    array('@min' => $start_date, '@max' => $date)
  );

  $query = <<<EOS
SELECT civicrm_contribution_recur.* FROM civicrm_contribution_recur
WHERE
    civicrm_contribution_recur.failure_retry_date BETWEEN :start AND :now
    AND civicrm_contribution_recur.contribution_status_id IN ({$failedStatusID} , {$failingStatusID})
    AND civicrm_contribution_recur.trxn_id LIKE 'RECURRING GLOBALCOLLECT%'
    AND ( civicrm_contribution_recur.end_date IS NULL )
EOS;

  // Add a limit.
  if ($limit > 0) {
    $query .= " LIMIT " . $limit;
  }

  $result = db_query( $query, array(
    ':start' => "{$start_date} 00:00:00",
    ':now' => "{$date} 23:59:59",
  ) );

  return $result->fetchAll();
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
 * TODO: deprecate
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
WHERE
    civicrm_contribution_recur.trxn_id = CONCAT_WS( ' ', 'RECURRING GLOBALCOLLECT', :order_id )
    OR civicrm_contribution_recur.trxn_id LIKE CONCAT_WS( ' ', 'RECURRING GLOBALCOLLECT', :order_id, '%' )
LIMIT 1
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
 * FIXME: should use 'installments' column, not processor_id
 *
 * These are the possible values for `contribution_status_id`:
 * XXX actually the ids are determined by querying the db
 *
 * - [1] => Completed - we have historically misused this but this should be the status when a recurring series has 'played out' - all
 * intended donations are received. For us this is a bit 'grey' as we don't allow the number of payments to be specified so what
 * does Completed vs Cancelled mean?
 * - [2] => Pending (we have historically misused this but this is the correct status when a recurring has started but no contribution is yet received)
 * - [3] => Cancelled (too many failures in the past, don't try to process this any more)
 * - [4] => Failed - too many failures no longer trying - however in the past this was misused as 'Failing'
 * - [5] => In Progress - the Recurring series has started & is in progress
 * - [6] => Not used, arguably should be removed as not part of the agreed flow - but still on new installs in core.
 * - [14] => Processing (there is a process actively trying to process this donation right now; used to avoid race conditions, if a contribution is stuck in this state it'll need manual intervention
 * and reconciliation)
 * - [15] => Failing (previous donation attempt failed, retry later)
 *
 * Completed, Failed and Cancelled should be inactive statuses if we follow the core expectations.
 *
 * @param integer $id The primary key of the record.
 *
 * @param array $errors
 *
 * @return int Returns the number of affected rows.
 * @throws \Civi\WMFException\WMFException
 */
function _recurring_globalcollect_update_record_failure($id, $errors) {

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
  $contribution_status_id = CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_ContributionRecur', 'contribution_status_id', 'Failing');

  // If there have been too many failures, cancel this payment permanently.
  if (
    $record['failure_count'] >= ( $failures_before_cancellation - 1 )
    || recurring_globalcollect_should_force_cancel( $errors )
  ) {
    $contribution_status_id = CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_ContributionRecur', 'contribution_status_id', 'Cancelled');
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
            'next_sched_contribution_date' => null,
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
 * Detect payment errors serious enough to warrant cancelling the subscription
 * without any retries. These are mostly codes which Mastercard would fine us
 * for retrying.
 * TODO: de-duplicate this list, or indicate severity somewhere in PaymentError
 *
 * @param PaymentError[] $errors
 * @return bool true if there's an error serious enough to force cancellation
 */
function recurring_globalcollect_should_force_cancel( $errors ) {
  $thirdRailErrorCodes = array(
    '430260',
    '430349',
    '430357',
    '430410',
    '430415',
    '430418',
    '430421',
    '430697',
    '485020',
    '4360022',
    '4360023',
    '430306',
    '430330',
    '430354',
  );
  foreach ( $errors as $error ) {
    if (get_class($error) == 'PaymentError' && array_search( $error->getErrorCode(), $thirdRailErrorCodes ) !== false ) {
      return true;
    }
  }
  return false;
}

/**
 * Update recurring payment to Processing status.
 *
 * The processing status indicates active processing of a transaction.
 *
 * @param integer $id The primary key of the record.
 *
 * @return integer  Returns the number of affected rows.
 *
 * @throws \Civi\WMFException\WMFException
 */
function _recurring_globalcollect_update_record_to_processing($id) {

  $result = recurring_globalcollect_get_payment_by_id($id);
  $record = (array) $result;

  $contributionStatus = CRM_Core_PseudoConstant::getName('CRM_Contribute_BAO_ContributionRecur', 'contribution_status_id', $record['contribution_status_id']);

  if (!in_array($contributionStatus, ['Completed', 'In Progress', 'Failing', 'Failed']) ) {
    throw new WMFException( WMFException::INVALID_RECURRING, t( 'The subscription is supposed to be in progress or failing before it can be processed. However for legacy reasons we keep processing Completed & Failed too' ), array('contribution_recur' => $record) );
  }

  $dbs = wmf_civicrm_get_dbs();
  $dbs->push( 'civicrm' );

  $processingStatusID = CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_ContributionRecur', 'contribution_status_id', 'Processing');
  $affected_rows = db_update( 'civicrm_contribution_recur' )
      ->fields( array(
          'contribution_status_id' => $processingStatusID,
      ) )
      ->condition( 'id', $id )
      ->execute();

  $dbs->pop();

  if ( !$affected_rows ) {
    throw new WMFException( WMFException::INVALID_RECURRING, t( 'The subscription was not marked as in processing.' ), array('contribution_recur' => $record) );
  }

  return $affected_rows;
}

/**
 * Update recurring payment for success.
 *
 * Payments will be rescheduled for the following month.
 *
 * @param integer $id The primary key of the record.
 *
 * @return int Returns the number of affected rows.
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
          'contribution_status_id' => CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_ContributionRecur', 'contribution_status_id', 'In Progress'),
          'next_sched_contribution_date' => $next_sched_contribution,
      ) )
      // FIXME: misuse of processor_id - it should indicate which payment
      // processor the recurring payment is registered with.
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
  $next_sched_contribution = isset($record['next_sched_contribution_date']) ? $record['next_sched_contribution_date'] : false;

  // Make sure $cycle_day is not empty
  if (empty($cycle_day)) {
    $message = 'cycle_day cannot be empty';
    throw new WMFException( WMFException::INVALID_RECURRING, $message, array('contribution_recur' => $record) );
  }

  // Make sure $frequency_interval is not empty
  if (empty($frequency_interval)) {
    $message = 'frequency_interval cannot be empty';
    throw new WMFException( WMFException::INVALID_RECURRING, $message, array('contribution_recur' => $record) );
  }

  // Make sure a valid interval is assigned
  if (!in_array($frequency_unit, $allowed_intervals)) {
    $message = 'Invalid frequency_unit [' . $frequency_unit . '] for recurring_globalcollect. Allowed intervals: [ ' . implode(', ', $allowed_intervals) . ' ]';
    throw new WMFException( WMFException::INVALID_RECURRING, $message, array('contribution_recur' => $record) );
  }

  // Make sure $next_sched_contribution is assigned
  if (empty($next_sched_contribution)) {
    $message = 'next_sched_contribution_date cannot be empty';
    throw new WMFException( WMFException::INVALID_RECURRING, $message, array('contribution_recur' => $record));
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

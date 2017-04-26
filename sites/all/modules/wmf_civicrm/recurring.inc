<?php

/**
 * Insert the recurring contribution record
 *
 * @todo Determine the watchdog level for errors.
 * @todo Are the dates being set with the correct value?
 *
 * @param array $msg
 * @param integer $contact_id
 * @param string $subscr_id
 * //FIXME: should not pass a contribution in, only subscr_id
 * @param array $contribution   The contribution record
 * @return boolean	Returns true on success
 */
function wmf_civicrm_message_contribution_recur_insert( $msg, $contact_id, $subscr_id, $contribution_record = null )
{
    $msg[ 'frequency_unit' ] = isset( $msg[ 'frequency_unit' ] ) ? $msg[ 'frequency_unit' ] : 'month';
    $msg[ 'frequency_interval' ] = isset( $msg[ 'frequency_interval' ] ) ? (integer) $msg[ 'frequency_interval' ] : 1;
    $msg[ 'installments' ] = isset( $msg[ 'installments' ] ) ? (integer) $msg[ 'installments' ] : 0;
    $msg['cancel'] = isset( $msg[ 'cancel' ] ) ? (integer) $msg[ 'cancel' ] : 0;

    if ( !isset( $msg[ 'recurring' ] ) && $msg[ 'recurring' ] != 1 ) {
        $error_message = t(
            'Payment is not marked as recurring, with the contact_id [!contact_id]',
            array( "!contact_id" => $contact_id )
        );
        throw new WmfException( 'IMPORT_SUBSCRIPTION', $error_message );
      }

    // Allowed frequency_units
    $frequency_units = array( 'month' );
    if ( !in_array( $msg[ 'frequency_unit' ], $frequency_units ) ) {
        $error_message = t(
            'Invalid `frequency_unit` specified [!frequency_unit]. Supported frequency_units: !frequency_units, with the contact_id [!contact_id]',
            array(
                "!frequency_unit" => $msg[ 'frequency_unit' ],
                "!frequency_units" => implode( ', ', $frequency_units ),
                "!contact_id" => $contact_id,
            )
        );
        throw new WmfException( 'IMPORT_SUBSCRIPTION', $error_message );
    }

    // Frequency interval is only allowed to be 1. FIXME
    if ( $msg[ 'frequency_interval' ] !== 1 ) {
        $error_message = t(
            '`frequency_interval` is only allowed to be set to 1, with the contact_id [!contact_id]',
            array("!contact_id" => $contact_id)
        );
        throw new WmfException('IMPORT_SUBSCRIPTION', $watchdog_message );
    }

    // installments is only allowed to be 0.
    if ( $msg[ 'installments' ] !== 0 ) {
        $error_message = t(
            '`installments` must be set to 0, with the contact_id [!contact_id]',
            array("!contact_id" => $contact_id)
        );
        throw new WmfException('IMPORT_SUBSCRIPTION', $error_message );
    }

    if ( !empty( $msg['subscr_id'] ) ) {
        $gateway_subscr_id = $msg['subscr_id'];
    } else {
        // trxn_id must be set.
        // FIXME: this should always be the subscr_id.
        if ( empty( $contribution_record[ 'trxn_id' ] ) ) {
            $error_message = t(
                '`trxn_id` must be set and not empty, with the contact_id [!contact_id]',
                array("!contact_id" => $contact_id)
            );
            throw new WmfException('IMPORT_SUBSCRIPTION', $error_message );
        }

        $gateway_subscr_id = $contribution_record['trxn_id'];
    }

    $msg['cycle_day'] = wmf_civicrm_get_cycle_day( $msg['date'] );

    $next_sched_contribution = wmf_civicrm_get_next_sched_contribution_date_for_month( $msg );
    
    $api = civicrm_api_classapi();
    $insert_params = array(
        'contact_id' => $contact_id,
        'amount' => $msg[ 'original_gross' ],
        'currency' => $msg[ 'original_currency' ],
        'frequency_unit' => $msg[ 'frequency_unit' ],
        'frequency_interval' => $msg[ 'frequency_interval' ],
        'installments' => $msg[ 'installments' ],
        'start_date' => wmf_common_date_unix_to_civicrm( $msg[ 'date' ] ),
        'create_date' => wmf_common_date_unix_to_civicrm( $msg[ 'date' ] ),
        'cancel_date' => ($msg['cancel'] ? wmf_common_date_unix_to_civicrm( $msg['cancel'] ) : null ),
        #FIXME:
        'processor_id' => 1,
        'cycle_day' => $msg[ 'cycle_day' ],
        'next_sched_contribution_date' => $next_sched_contribution,
        'trxn_id' => $gateway_subscr_id,

        'version' => 3,
    );
    $success = $api->ContributionRecur->Create( $insert_params );
    if ( !$success ) {
        throw new WmfException( 'IMPORT_SUBSCRIPTION', $api->errorMsg() );
    }
    $contribution_recur_id = $api->id;

    if ( $contribution_record ) {
        // Link the contribution to the recurring payment
        $link_contribution_status = wmf_civicrm_update_contribution_for_recurring( $contribution_record['id'], $contribution_recur_id );
        return $link_contribution_status;
    } else {
        return true;
    }
}

/**
 * Link a recurring payment to the contribution record
 *
 * @param integer $contribution_id
 * @param integer $contribution_recur_id
 */
function wmf_civicrm_update_contribution_for_recurring( $contribution_id, $contribution_recur_id )
{
    // contribution_id must be set and not null or zero
    if ( empty( $contribution_id ) ) {
        $error_message = t('`contribution_id` must be set and not empty.');
        throw new WmfException('IMPORT_SUBSCRIPTION', $error_message);
    }

    // contribution_recur_id must be set and not null or zero
    if ( empty( $contribution_recur_id ) ) {
        $error_message = t('`contribution_recur_id` must be set and not empty.');
        throw new WmfException('IMPORT_SUBSCRIPTION', $error_message);
    }

    $api = civicrm_api_classapi();
    $update_params = array(
        'id' => $contribution_id,
        'contribution_recur_id' => $contribution_recur_id,

        'version' => 3,
    );
    if ( !$api->Contribution->Create($update_params) ) {
        $error_message = t(
            'There was a problem linking the contribution [!contribution_id] to the recurring contribution [!contribution_recur_id]: !msg',
            array(
                '!contribution_id' => $contribution_id,
                '!contribution_recur_id' => $contribution_recur_id,
                '!msg' => $api->errorMsg(),
            )
        );
        throw new WmfException('IMPORT_SUBSCRIPTION', $error_message);
    } else {
        watchdog( 'wmf_civicrm', 'Successfully linked the contribution  [!contribution_id] to the recurring contribution [!contribution_recur_id]', array( '!contribution_id' => $contribution_id, '!contribution_recur_id' => $contribution_recur_id ) );
        return true;
    }
}

/**
 * Select a set of recurring payments by `next_sched_contribution`, that need to be processed for the current day.
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
 * TODO: other gateways than GC
 * @todo The field `civicrm_payment_processor`.`payment_processor_type` should be set.
 * @todo Implement $contributionStatus = CRM_Contribute_PseudoConstant::contributionStatus( null, 'name' );
 *
 * @return array of contribution_recur objects
 */
function wmf_civicrm_get_next_sched_contribution($limit = 1, $date = 'now', $run_missed_days = 0)
{
    $oldTimezone = date_default_timezone_get();
    date_default_timezone_set( "UTC" );

    $date = date('Y-m-d', strtotime($date));

    $start_day = $date . ' 00:00:00';
    $end_day = $date . ' 23:59:59';

    $start_day_stamp = strtotime($start_day);
    $end_day_stamp = strtotime($end_day);

    if ($run_missed_days > 0) {

        $decrement_date = '-' . $run_missed_days . ' day';

        $start_day = date('Y-m-d 00:00:00', strtotime($decrement_date, $start_day_stamp));
    }

    date_default_timezone_set( $oldTimezone );

    $contribution_status_id = civicrm_api_contribution_status('Completed');

    watchdog(
        'recurring_globalcollect',
        'Looking for next scheduled contributions in timeframe @min -> @max',
        array('@min' => $start_day, '@max' => $end_day)
	);

    $query = <<<EOS
SELECT * FROM civicrm_contribution_recur
WHERE
    civicrm_contribution_recur.next_sched_contribution_date BETWEEN :start AND :end
    AND civicrm_contribution_recur.trxn_id LIKE 'RECURRING GLOBALCOLLECT%'
    AND ( civicrm_contribution_recur.end_date IS NULL )
    AND civicrm_contribution_recur.contribution_status_id = :status_id
LIMIT {$limit}
EOS;

    $dbs = wmf_civicrm_get_dbs();
    $dbs->push( 'civicrm' );

    $res = db_query( $query, array(
        ':start' => $start_day,
        ':end' => $end_day,
        ':status_id' => $contribution_status_id,
    ) );

    return $res->fetchAll();
}

/**
 * wmf_civicrm_get_next_sched_contribution_date_for_month
 *
 * @param $record contribution_recur db record, containing at least:
 *     frequency_interval, cycle_day
 * @param $nowstamp optional timestamp at which to perform the calculation, otherwise now()
 *
 * @return  string  Returns a date stamp in the format 'Y-m-d H:i:s' => 2011-12-31 00:00:00
 */
function wmf_civicrm_get_next_sched_contribution_date_for_month( $record, $nowstamp = null )
{
    $triggered_for_date = wmf_civicrm_get_last_trigger_date( $record, $nowstamp );
    //$frequency_interval and $cycle_day will, at this point, have been found in $record.
    $frequency_interval = (integer) $record['frequency_interval'];
    $cycle_day = $record['cycle_day'];

    $scheduled_date_stamp = $triggered_for_date;
    $added = 0;
    while ( gmdate('Y-m-d', $triggered_for_date) >= gmdate('Y-m-d', $scheduled_date_stamp) && ( $added < $frequency_interval ) ){
        //this will happen at least once.
        $scheduled_date_stamp = wmf_civicrm_increment_date_to_target_day( $scheduled_date_stamp, 'month', $cycle_day );
        $added += 1;
    }

    return gmdate('Y-m-d H:i:s', $scheduled_date_stamp);
}

/**
 * Calculates the last date this payment should have been triggered for,
 * regardless of the actual date, or the last recorded date in the schedule.
 *
 * @param array $record An array that contains, at least, the cycle day. Passing
 * this around in record format because that's what the rest of the module does.
 * @param int|null $nowstamp optional unix timestamp if we are performing the
 * calculation for another date
 *
 * @TODO: Stop passing around the whole record.
 * @return timestamp A midnight timestamp for the day that should have triggered
 * this recurring transaction.
 */
function wmf_civicrm_get_last_trigger_date( $record, $nowstamp = null )
{
    if ( $nowstamp === null ) {
        $nowstamp = time();
    }

    //Instead of adding to now, we have to look for the last time the cycle date
    //should have been triggered, regardless of when the transaction actually went through.

    //TODO: This needs to implement more frequency intervals. For now, though, we only use monthly, so...
    if ( !array_key_exists( 'cycle_day', $record ) || !is_numeric( $record['cycle_day'] ) ){
        return $nowstamp;
    } else {
        $cycle_day = (integer) $record['cycle_day'];
    }

    $month = (int) gmdate('n', $nowstamp);
    $year = (int) gmdate('Y', $nowstamp);

    // Build a timestamp for the cycle day in this month
    // If we are still in the same month, this will be the correct value. If we're in the next month,
    // it'll be the wrong value and it'll be in the future; we fix that up below.
    $last_trigger = gmmktime(0, 0, 0, $month, wmf_civicrm_get_cycle_day_for_month( $cycle_day, $month, $year ), $year);

    // So... we actually want last month's date... to psych out the code which
    // will add a month.  Note that this is not necessarily the true last
    // trigger date, just our best-case guess.
    while ( $last_trigger > $nowstamp && ( $last_trigger - $nowstamp ) > 60*60*24*7 ){
        //decrement the month until it was in the past.
        --$month;
        if ( $month < 1 ){
            $month = 12;
            --$year;
        }
        $last_trigger = gmmktime(0, 0, 0, $month, wmf_civicrm_get_cycle_day_for_month( $cycle_day, $month, $year ), $year);
    }
    return $last_trigger;
}

/**
 * @param timestamp $date as unix seconds
 * @return day of the month for this date
 */
function wmf_civicrm_get_cycle_day( $date ) {
    return intval( gmdate( 'j', $date ) );
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
function wmf_civicrm_increment_date_to_target_day( $date, $interval = 'month', $cycle_day = null )
{
    switch ($interval){ //just making it slightly nicer in here for the next guy
    case 'month':
    default:
        if ( is_null( $cycle_day ) ){
          $cycle_day = wmf_civicrm_get_cycle_day( $date );
        }
        $month = (int) gmdate('n', $date);
        $year = (int) gmdate('Y', $date);

        $month += 1;
        //if we wanted to edit this to handle adding more than one month at
        //a time, we could do some fun stuff with modulo here.
        if ( $month > 12 ){
          $month = 1;
          $year += 1;
        }

        $target_day = wmf_civicrm_get_cycle_day_for_month($cycle_day, $month, $year);

        $next_date = gmmktime(0, 0, 0, $month, $target_day, $year);
        return $next_date;
    }
}

/**
 * @param int $cycle_day - target day of the month for this subscription
 * @param int $month - target month
 * @param int $year - target year.
 * @return int The day of the specified month and year most appropriate for the
 * target cycle day. This will only change if the target day doesn't exist in certain months.
 */
function wmf_civicrm_get_cycle_day_for_month($cycle_day, $month, $year){
    $last_day = wmf_civicrm_cal_days_in_month( $month );
    if ( $cycle_day > $last_day ){
        return $last_day;
    }
    return $cycle_day;
}

/**
 * Cheap port of cal_days_in_month, which is not supported in hhvm
 *
 * Ignores the leap year cos we don't care.
 *
 * @param integer $month One-based month number
 */
function wmf_civicrm_cal_days_in_month( $month ) {
    $lookup = array(
        '1' => 31,
        '2' => 28,
        '3' => 31,
        '4' => 30,
        '5' => 31,
        '6' => 30,
        '7' => 31,
        '8' => 31,
        '9' => 30,
        '10' => 31,
        '11' => 30,
        '12' => 31,
    );
    return $lookup[$month];
}

/**
 * Get the first contribution in a recurring series
 *
 * @param integer $contribution_recur_id
 *
 * @return array $contribution
 *
 * @throws WmfException if no matching subscription exists.
 */
function wmf_civicrm_get_initial_recurring_contribution( $contribution_recur_id ) {
    $sql = "
SELECT * FROM civicrm_contribution
WHERE contribution_recur_id = %1
ORDER BY id ASC
LIMIT 1";

    $res = CRM_Core_DAO::executeQuery( $sql, array(
        '1' => array( $contribution_recur_id, 'Integer' ),
    ) );

    $out = wmf_civicrm_dao_to_list( $res );
    if ( !$out ) {
        throw new WmfException( 'MISSING_PREDECESSOR', 'No such recurring contribution, id=' . $contribution_recur_id );
    }
    return array_pop($out);
}

/**
 * Get recurring record in CiviCRM for the given subscriber id
 *
 * @deprecated Use wmf_civicrm_get_gateway_subscription instead.
 *
 * @param string $subscr_id
 * @return object|false
 */
function wmf_civicrm_get_recur_record( $subscr_id ) {
  $query = "SELECT * FROM civicrm_contribution_recur WHERE trxn_id = %1";

  $dao = CRM_Core_DAO::executeQuery( $query, array(
    1 => array( $subscr_id, 'String' ),
  ) );

  if ( !$dao->fetch() ) {
    return false;
  }

  return $dao;
}

/**
 * Get recurring record in CiviCRM for the given subscriber id
 *
 * There is tension between trxn_id as searchable index and full gateway style
 * at the moment, so we search using either style: either bare gateway
 * subscription ID, or trxn-style full natural key.
 *
 * @param string $subscr_id
 * @return object|false
 */
function wmf_civicrm_get_gateway_subscription( $gateway, $subscr_id ) {
  $full_trxn_id = "RECURRING {$gateway} {$subscr_id}";

  $query = "select *
    from civicrm_contribution_recur
    where
        trxn_id = %1
        or trxn_id = %2";
  $dao = CRM_Core_DAO::executeQuery( $query, array(
    1 => array( $subscr_id, 'String' ),
    2 => array( $full_trxn_id, 'String' ),
  ) );

  if ( !$dao->fetch() ) {
    return false;
  }

  return $dao;
}

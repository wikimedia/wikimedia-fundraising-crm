<?php

class SubscriptionBatch {
    /**
     * recurring_globalcollect_batch_process
     *
     * This is the entry function for this module.
     *
     * This function is invoked here: drush_recurring_globalcollect() 
     * @see drush_recurring_globalcollect() 
     *
     * Validation is performed here: drush_recurring_globalcollect_validate()
     * @see drush_recurring_globalcollect_validate()
     *
     * @param array $options 
     * - $options['batch'] The number of contributions to process. If empty or not set or zero, no contributions will be processed.
     * - $options['date'] @uses strtotime()
     * - $options['url'] Used for testing and overriding the url
     */
    function recurring_globalcollect_batch_process($options = array()) {

      // The number of contributions to process
      if ( array_key_exists( 'batch', $options ) ) {
        $batch = intval( $options['batch'] );
      } else {
        $batch = intval( variable_get('recurring_globalcollect_batch', 0) );
      }

      $run_missed_days = (integer) variable_get('recurring_globalcollect_run_missed_days', 0);
      
      watchdog('recurring_globalcollect', 'Attempting to process up to ' . $batch . ' recurring contribution(s).');

      $contribution_batch = wmf_civicrm_get_next_sched_contribution($batch, 'now', $run_missed_days);
      watchdog(
        'recurring_globalcollect',
        'Query returned @count messages to process',
        array('@count' => count($contribution_batch))
      );
      $result = recurring_globalcollect_batch_charge($contribution_batch, $options);

      $processed = count($result['succeeded']) + count($result['failed']);
      if ($processed > 0) {
        $message = "Processed $processed contribution(s).";
        if ( $result['failed'] ) {
            $message .= " Encountered ".count($result['failed'])." failures.";
        }
        watchdog('recurring_globalcollect', $message);
      }
      else {
        watchdog('recurring_globalcollect', 'No contributions processed.');
      }

      // Process retries
      watchdog('recurring_globalcollect', 'Attempting to retry up to ' . $batch . ' previously failed contribution(s).');
      $retry_batch = recurring_globalcollect_get_failure_retry_batch($batch, 'now', $run_missed_days);
      watchdog(
        'recurring_globalcollect',
        'Query returned @count messages to process',
        array('@count' => count($contribution_batch))
      );
      $result = recurring_globalcollect_batch_charge($retry_batch, $options);

      $processed = count($result['succeeded']) + count($result['failed']);
      if ($processed > 0) {
        $message = "Retried $processed contribution(s).";
        if ( $result['failed'] ) {
            $message .= " Encountered ".count($result['failed'])." failures.";
        }
        watchdog('recurring_globalcollect', $message);
      } else {
        watchdog('recurring_globalcollect', 'No retries processed.');
      }
    }

    /**
     * Process a batch
     *
     * @param  array  $options
     *
     * $options:
     * - string  $date The date to process.
     *
     * You are not allowed to process future dates. This generates an error
     *
     * $options['date'] @uses strtotime()
     *
     * The default date to process is today.
     *
     * The default process is next_sched_contribution.
     *
     * If you pick an incorrect process, an error will be generated.
     *
     * @uses recurring_globalcollect_process_error()
     * @uses recurring_globalcollect_process_validate_options()
     *
     * @return  boolean  Returns false on error. Returns true if contributions were processed. Returns false if no contributions are ready to be processed.
     */
    function recurring_globalcollect_batch_charge($contribution_batch, $options = array())
    {
      $succeeded = array();
      $failed = array();
      foreach ($contribution_batch as $subscription)
      {
          try {
              $status = recurring_globalcollect_charge( $subscription, $options );

              if ($status) {
                  $succeeded[] = $contribution_recur;
              } else {
                  $failed[] = $contribution_recur;
              }
          }
          catch ( WmfException $e )
          {
              $failed[] = $contribution_recur;
              if ( !$e->isNoEmail() ) {
                  wmf_common_failmail( 'recurring_globalcollect', $e, $contribution_recur );
              }
              if ( $e->isFatal() ) {
                  break;
              }
          }
          catch (Exception $e) {
              $message = 'Batch processing aborted: ' . $e->getMessage();
              $e = new WmfException( 'UNKNOWN', $message, $contribution_recur);
              $failed[] = $contribution_recur;
              break;
          }
      }

      return array(
          'succeeded' => $succeeded,
          'failed' => $failed,
      );
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

      $contribution_status_id = civicrm_api_contribution_status('Failed');

      watchdog(
        'recurring_globalcollect',
        'Looking for failed contributions in timeframe @min 00:00:00 -> @max 23:59:59',
        array('@min' => $start_date, '@max' => $date)
      );

      $query = <<<EOS
    SELECT civicrm_contribution_recur.* FROM civicrm_contribution_recur
    WHERE
        civicrm_contribution_recur.failure_retry_date BETWEEN :start AND :now
        AND civicrm_contribution_recur.contribution_status_id = :failed_status
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
        ':failed_status' => $contribution_status_id,
      ) );

      return $result->fetchAll();
    }
}

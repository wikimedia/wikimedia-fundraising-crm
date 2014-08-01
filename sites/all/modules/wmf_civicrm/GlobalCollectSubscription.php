<?php

class SelfRecurringSubscription {
    /**
     * Connect to GlobalCollect and process recurring charge
        // TODO: couple more closely to recordPayment
     *
     * @param array $options Accepts the following optional keys:
     *      contribution_tags - array of tags to associate with the contribution
     *
     * @return array adapter result details
     *
     * @throws WmfException if the payment fails or any other error occurs.
     */
    abstract function charge( $options = array());

    function recordPayment() {
        $msg = $this->createPaymentMessage();

        $contribution = wmf_civicrm_contribution_message_import( $msg );
        return $contribution;
    }

    abstract function createPaymentMessage();

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
     * @return integer  Returns the number of affected rows.
     */
    function setFailure() {
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
}

class GlobalCollectSubscription extends SelfRecurringSubscription {
    function charge( $options = array() ){
      $adapter = DonationInterface::getAdapter( 'GlobalCollect' );

      watchdog('recurring_globalcollect', 'Processing recurring charge: <pre>' . print_r($this, true) . '</pre>');

      $adapter->load_request_data( $values );
      
      $this->setInProgress();

      $result = $adapter->do_transaction('Recurring_Charge');
      
      // If success, add a record to the contribution table and send a thank you email.
      if ($result['status'] && empty($result['errors'])) {
        // Mark this donation as successful, and reschedule it for next month
        // This is done before anything else, otherwise any errors that occur while storing the contribution
        // record in civi might cause this subscription to end up in a weird state and not recur correctly.
        // If storing the donation in civi fails, that's not a big deal, we'll get the data eventually
        // by reconciling the data we get from the payment processor.

        $this->setSuccessful();

        $this->recordPayment();

        return $result;
      }
      else
      {
        _recurring_globalcollect_update_record_failure($contribution_recur_id);
        throw new WmfException( 'PAYMENT_FAILED', 'recurring charge failed', $result);
      }

      return $result;
    }

    /**
     * Create and return a message which is a payment on the given subscription
     *
     * FIXME: push down into GlobalCollectSubscription
     *
     * @return array queue message for a new payment
     */
    function createPaymentMessage() {
        $contribution_recur = (array)recurring_globalcollect_get_payment_by_id( $contribution_recur_id );
        $initial_contribution = wmf_civicrm_get_initial_recurring_contribution( $contribution_recur_id );
        if ( !$initial_contribution ) {
            throw new WmfException( 'INVALID_RECURRING', "No initial contribution for this subscription" );
        }

        try {
            $transaction = WmfTransaction::from_unique_id( $contribution_recur['trxn_id'] );
        } catch ( Exception $ex ) {
            throw new WmfException( 'INVALID_RECURRING', $ex->getMessage(), $contribution_recur );
        }

        $msg = array(
        // Copy stuff from the subscription, and increment the EFFORTID
            'amount' => $contribution_recur['amount'],
            'contact_id' => $contribution_recur['contact_id'],
            'effort_id' => $contribution_recur['processor_id'],
            'order_id' => $transaction->gateway_txn_id,
            'currency_code' => $contribution_recur['currency'],
            'payment_product' => '',

            'contribution_type_id' => $initial_contribution['contribution_type_id'],
            'payment_instrument_id' => $initial_contribution['payment_instrument_id'],

            'gateway' => 'globalcollect',
            'gross' => $contribution_recur['amount'],
            'currency' => $contribution_recur['currency'],
            'gateway_txn_id' => $transaction->gateway_txn_id . '-' . $contribution_recur['processor_id'],
            'payment_method' => 'cc',
            'payment_submethod' => '',
            'date' => time(),

            'contribution_tags' => isset( $options['contribution_tags'] ) ? $options['contribution_tags'] : array(),

            'contribution_recur_id' => $contribution_recur['id'],
            'recurring' => true,

            //FIXME: ignored cos we already have a contact
            'email' => 'nobody@wikimedia.org',
        );

        wmf_common_set_message_source( $msg, 'direct', 'Recurring GlobalCollect' );

        return $msg;
    }
}

<?php

use SmashPig\Core\DataStores\QueueWrapper;
use SmashPig\Core\SequenceGenerators\Factory;

/**
 * Insert a record into contribution_tracking table
 *
 * Primarily used when a record does not already exist in the table for a
 * particular transaction.  Rare, but inserting some data for a trxn when
 * absent helps facilitate better analytics.
 *
 * @param array $values associative array of columns => values to insert
 *  into the contribution tracking table
 *
 * @return int the contribution_tracking id
 *
 * @throws \Exception
 */
function wmf_civicrm_insert_contribution_tracking($values) {
  $generator = Factory::getSequenceGenerator('contribution-tracking');
  $contribution_tracking_id = $generator->getNext();
  $values['id'] = $contribution_tracking_id;
  QueueWrapper::push('contribution-tracking', $values);
  \Civi::log('wmf')->info('wmf_civicrm: Queued new contribution_tracking entry {id}', ['id' => $contribution_tracking_id]);
  return $contribution_tracking_id;
}

/**
 * Update contribution_tracking record with a pointer to the contribution.
 *
 * @param array $msg
 * @param array $contribution
 */
function wmf_civicrm_message_update_contribution_tracking($msg, $contribution) {
  if (!empty($msg['contribution_tracking_id'])) {
    QueueWrapper::push('contribution-tracking', [
      'id' => $msg['contribution_tracking_id'],
      'contribution_id' => $contribution['id'],
    ]);
    \Civi::log('wmf')->info('wmf_civicrm: Queued update to contribution_tracking for {id}', ['id' => $msg['contribution_tracking_id']]);
    return TRUE;
  }
}

/**
 * Confirm that we want to update the contribution tracking record.
 *
 * We currently update the contribution tracking record for the following:
 * - initial recurring contributions
 * - initial recurring UPI contributions
 * - applicable non-recurring contributions (all excluding matching gifts)
 *
 * @param array $msg
 * @param bool $isInitialRecurring
 *
 * @return bool
 */
function wmf_civicrm_should_update_contribution_tracking(array $msg, bool $isInitialRecurring) : bool {

  // Update the contribution tracking record for initial(first) recurring UPI contributions
  // Note: The UpiDonationsQueueConsumer creates the contribution_recur record for
  // UPI initial recurring contributions so $isInitialRecurring is false here, but we still
  // want to update contribution_tracking
  if (isInitialUpiRecurringContribution($msg)) {
    return TRUE;
  }

  // Do not update the contribution tracking record for matching gift contributions.
  // Note: we don't need to check this as it will return false for these cases by default.
  // I guess leaving it here makes that intention more explicit.
  if (isMatchingGiftContribution($msg['gateway_txn_id'])) {
    return FALSE;
  }

  // Update the contribution tracking record for initial(first) recurring contributions
  if ($isInitialRecurring) {
    return TRUE;
  }

  // Update the contribution tracking record for applicable non-recurring contributions
  if (!($msg['recurring'])) {
    return TRUE;
  }

  return FALSE;
}

/**
 *
 * @param array $msg
 *
 * @return bool
 */
function isInitialUpiRecurringContribution(array $msg) : bool {
  $payment_submethod = $msg['payment_submethod'] ?? NULL;
  $recurring_payment_token = $msg['recurring_payment_token'] ?? NULL;

  return $payment_submethod === 'upi' && !empty($recurring_payment_token);
}

/**
 * @param $gateway_txn_id
 *
 * @return false|string
 */
function isMatchingGiftContribution($gateway_txn_id) {
  return stristr($gateway_txn_id, '_matched');
}

function wmf_civicrm_get_contribution_tracking($msg) {
  if (array_key_exists('contribution_tracking_id', $msg)) {
    return db_select('contribution_tracking', 'contribution_tracking')
      ->fields('contribution_tracking')
      ->condition('id', $msg['contribution_tracking_id'])
      ->execute()
      ->fetchAssoc();
  }
  else {
    return FALSE;
  }
}

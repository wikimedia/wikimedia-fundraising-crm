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
  watchdog('wmf_civicrm', "Queued new contribution_tracking entry %id", ['%id' => $contribution_tracking_id], WATCHDOG_INFO);
  return $contribution_tracking_id;
}

/**
 * Update contribution_tracking record with a pointer to the contribution.
 *
 * @param array $msg
 * @param array $contribution
 */
function wmf_civicrm_message_update_contribution_tracking($msg, $contribution) {

  if (array_key_exists('contribution_tracking_id', $msg)) {
    QueueWrapper::push('contribution-tracking', [
      'id' => $msg['contribution_tracking_id'],
      'contribution_id' => $contribution['id'],
    ]);
    watchdog('wmf_civicrm', 'Queued update to contribution_tracking for %id', ['%id' => $msg['contribution_tracking_id']], WATCHDOG_INFO);
      return TRUE;
  }
}

/**
 * Confirm that we wanna update the contribution tracking record.
 *
 * @param array $msg
 * @param bool $isInitialRecurring
 *
 * @return bool
 */
function wmf_civicrm_should_update_contribution_tracking($msg, $isInitialRecurring) {
  if (stristr($msg['gateway_txn_id'], '_matched')) {
    return FALSE;
  }
  elseif (!$msg['recurring'] || $isInitialRecurring) {
    return TRUE;
  }
  else {
    return FALSE;
  }
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

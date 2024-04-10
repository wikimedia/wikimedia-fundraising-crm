<?php

use \Civi\Api4\ContributionTracking;
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

function wmf_civicrm_get_contribution_tracking($msg) {
  if (array_key_exists('contribution_tracking_id', $msg)) {
    return ContributionTracking::get(FALSE)
      ->addWhere('id', '=', $msg['contribution_tracking_id'])
      ->execute()
      ->first();
  }
  else {
    return FALSE;
  }
}

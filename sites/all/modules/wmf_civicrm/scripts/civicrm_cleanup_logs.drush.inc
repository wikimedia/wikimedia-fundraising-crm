<?php

/**
* Implementation of hook_drush_command()
*/
function civicrm_cleanup_logs_drush_command() {
  $items = array();
  $items['civicrm-cleanup-logs'] = array(
    'description' => 'Clean up log bloat in civicrm settings table',
    'options' => array(
      'batch' => "Batch size",
      'threshold' => 'Threshold for aborting.',
      'threshold_period' => 'Number of minutes in the threshold period',
    ),
  );

  return $items;
}

/**
 * Implementation of hook_drush_help().
 */
function civicrm_cleanup_logs_drush_help($section) {
  switch ( $section ) {
  case 'drush:civicrm-cleanup-logs':
    return dt('Cleanup data in log table (specifically settings)');
  }
}

/**
 * Cleanup the log_civicrm_setting table.
 *
 * This got bloated by the omnimail job.
 *
 * @throws \CiviCRM_API3_Exception
 */
function drush_civicrm_cleanup_logs() {
  module_invoke('civicrm', 'initialize');

  require_once 'drush_threshholds.php';
  $threshold = (int) drush_get_option('threshold');
  if ($threshold) {
    $thresholdNumberOfMinutes = (int) drush_get_option('threshold_period', 5);
    if (_drush_civicrm_queue_is_backed_up($threshold, $thresholdNumberOfMinutes)) {
      return;
    }
  }

  $batch_size = (integer) drush_get_option('batch', 1000);
  while ($batch_size > 0) {

    $logConnID = CRM_Core_DAO::singleValueQuery("
    SELECT log_conn_id FROM log_civicrm_setting WHERE name IN ('omnimail_omnirecipient_load', 'omnimail_omnigroupmembers_load')
    LIMIT 1
  ");

    if (empty($logConnID)) {
      watchdog('civicrm_cleanup_log', 'No more cruft to process');
      return;
    }
    CRM_Core_DAO::executeQuery("DELETE FROM log_civicrm_setting WHERE log_conn_id = '{$logConnID}'");
    $batch_size--;
  }
  watchdog('civicrm_cleanup_logs', 'Cleaned omnimail cruft but not finished.');
}

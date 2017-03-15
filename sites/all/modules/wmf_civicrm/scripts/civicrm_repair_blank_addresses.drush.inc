<?php

/**
* Implementation of hook_drush_command()
*/
function civicrm_repair_blank_addresses_drush_command() {
  $items = array();
  $items['civicrm-repair-address'] = array(
    'description' => 'Repair address lost on blank merging over a valid address',
    'options' => array(
      'batch' => "Batch size",
      'threshold' => 'Threshold for aborting. If there are more than this number of contributions in the threshold period then abort.',
      'threshold_period' => 'Number of minutes in the threshold period',
    ),
  );

  return $items;
}

/**
 * Implementation of hook_drush_help().
 */
function civicrm_repair_blank_addresses_drush_help($section) {
  switch ( $section ) {
  case 'drush:civicrm-repair-address':
    return dt('Repair addresses where blank emails have merged over real emails');
  }
}

/**
 * Repair addresses on a bunch of contacts.
 *
 * We are rolling back a situation where a contact with a blank address was merged into
 * a contact with valid address data, and overwrote the valid data. Blank address data
 * was common until early 2017.
 *
 * On staging this script took a very long time so moving to drush so it can run in batches.
 *
 * @throws \CiviCRM_API3_Exception
 */
function drush_civicrm_repair_blank_addresses_civicrm_repair_address() {
  module_invoke('civicrm', 'initialize');

  // This threshold stuff is a bit cheaty since I'm leveraging another drush file function.
  // OTOH this should be a temporary script & maybe no-one will spot it....
  require_once 'civicrm_merge.drush.inc';
  $threshold = (int) drush_get_option('threshold');
  if ($threshold) {
    $thresholdNumberOfMinutes = (int) drush_get_option('threshold_period', 5);
    if (_drush_civicrm_queue_is_backed_up($threshold, $thresholdNumberOfMinutes)) {
      return;
    }
  }

  $startVariableName = 'civicrm_repair_address_last_processed_id';
  $start = variable_get($startVariableName, 1);
  $batch_size = (integer) drush_get_option('batch', 5000);
  $maxAffectedID = CRM_Core_DAO::singleValueQuery("
    SELECT max(id) FROM civicrm_address
    WHERE street_address IS NULL
     AND city IS NULL
     AND postal_code IS NULL
     AND state_province_id IS NULL
     AND country_id IS NULL
     AND id > $start
  ");

  if (empty($maxAffectedID)) {
    watchdog('civicrm_repair_address', 'No more addresses to process');
    return;
  }

  watchdog('civicrm_repair_address', 'Repairing up to %batch addresses from address id %start', array('%start' => $start, '%batch' => $batch_size), WATCHDOG_INFO);
  require_once  __DIR__ . '/../update_restore_addresses.php';
  $highestRepairedID = repair_lost_addresses_batch($batch_size, $start);

  variable_set($startVariableName, $highestRepairedID);
  watchdog('civicrm_repair_address', 'Repaired address range: %start to %end', array('%start' => $start, '%end' => $highestRepairedID), WATCHDOG_INFO);
  drush_print("Processed id range $start to " . $highestRepairedID);
}

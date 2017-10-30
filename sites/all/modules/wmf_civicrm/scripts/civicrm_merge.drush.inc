<?php

/**
* Implementation of hook_drush_command()
*/
function civicrm_merge_drush_command() {
  $items = array();

  $items['civicrm-merge'] = array(
    'description' => 'Merge CiviCRM contacts in batches',
    'options' => array(
      'batch' => "Batch size",
      'rule_group_id' => "Rule ID to use - defaults to Individual Unsupervised (email match)",
      'group_id' => "Limit to a group of contacts (default is all)",
      'threshold' => 'Threshold for aborting. If there are more than this number of contributions in the threshold period then abort.',
      'threshold_period' => 'Number of minutes in the threshold period',
    ),
  );

  return $items;
}

/**
 * Implementation of hook_drush_help().
 */
function civicrm_merge_drush_help($section) {
  switch ( $section ) {
  case 'drush:civicrm-merge':
    return dt( 'Merge a bunch of CiviCRM contacts' );
  }
}

/**
 * Merge a batch of contacts.
 *
 * @throws \CiviCRM_API3_Exception
 */
function drush_civicrm_merge() {
  require_once 'drush_threshholds.php';
  module_invoke('civicrm', 'initialize');

  $threshold = (int) drush_get_option('threshold');
  if ($threshold) {
    $thresholdNumberOfMinutes = (int) drush_get_option('threshold_period', 5);
    if (_drush_civicrm_queue_is_backed_up($threshold, $thresholdNumberOfMinutes)) {
      return;
    }
  }



  $ruleGroupID = drush_get_option('rule_group_id');
  $groupID = drush_get_option('group_id');
  $startVariableName = 'civicrm_merge_batch_last_processed_id' . $ruleGroupID . '_' . $groupID;
  $start = variable_get($startVariableName, 1);
  $batch_size = (integer) drush_get_option('batch', variable_get('wmf_civicrm_merge_batch_size', 1000));
  $end = CRM_Core_DAO::singleValueQuery("
    SELECT max(id)
    FROM (
      SELECT id FROM civicrm_contact WHERE id > %1 AND is_deleted = 0 ORDER BY id ASC LIMIT %2
    ) as c",
    array(1 => array($start, 'Integer'), 2 => array($batch_size, 'Integer'))
  );

  if (empty($end)) {
    watchdog('civicrm_merge', 'Deduping contact range from %start - there are no more contacts to dedupe.', array('%start' => $start), WATCHDOG_INFO);
    return;
  }

  $mergeParams = array(
    'criteria' => array(
      'contact' => array('id' => array('BETWEEN' => array($start, $end))),
    ),
  );
  if ($groupID) {
    $mergeParams['gid'] = $groupID;
  }
  if ($ruleGroupID) {
    $mergeParams['rule_group_id'] = $ruleGroupID;
  }

  watchdog('civicrm_merge', 'Deduping contact range: %start to %end', array('%start' => $start, '%end' => $end), WATCHDOG_INFO);
  $result = civicrm_api3('Job', 'process_batch_merge', $mergeParams);

  variable_set($startVariableName, $end);
  watchdog('civicrm_merge', 'Deduped contact range: %start to %end', array('%start' => $start, '%end' => $end), WATCHDOG_INFO);
  drush_print("Processed id range $start to " . $end . '
  Merged ' . count($result['values']['merged']) . ". Skipped " . count($result['values']['skipped']));
}

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
  module_invoke('civicrm', 'initialize');

  $ruleGroupID = drush_get_option('rule_group_id');
  $groupID = drush_get_option('group_id');
  $startVariableName = 'civicrm_merge_batch_last_processed_id' . $ruleGroupID . '_' . $groupID;
  $start = variable_get($startVariableName, 1);
  $batch_size = (integer) drush_get_option('batch', variable_get('wmf_civicrm_merge_batch_size', 1000));

  $mergeParams = array(
    'criteria' => array(
      'contact' => array('id' => array('BETWEEN' => array($start, $start + $batch_size))),
    ),
  );
  if ($groupID) {
    $mergeParams['gid'] = $groupID;
  }
  if ($ruleGroupID) {
    $mergeParams['rule_group_id'] = $ruleGroupID;
  }

  $result = civicrm_api3('Job', 'process_batch_merge', $mergeParams);

  variable_set($startVariableName, $start + $batch_size);
  drush_print("Processed id range $start to " . $start + $batch_size . '
  Merged ' . count($result['values']['merged']) . ". Skipped " . count($result['values']['skipped']));
}

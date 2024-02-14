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
  switch ($section) {
    case 'drush:civicrm-merge':
      return dt('Merge a bunch of CiviCRM contacts');
  }
}

/**
 * Merge a batch of contacts.
 *
 * @throws \CRM_Core_Exception
 */
function drush_civicrm_merge() {
  module_invoke('civicrm', 'initialize');

  $event = \Civi\Core\Event\GenericHookEvent::create([
    'status' => 'active',
    'queue_name' => '',
    'queue_spec' => '',
  ]);
  \Civi::dispatcher()->dispatch('hook_civicrm_queueActive', $event);
  if ($event->status !== 'active') {
    return;
  }

  $ruleGroupID = drush_get_option('rule_group_id');
  $groupID = drush_get_option('group_id');
  $startVariableName = 'civicrm_merge_batch_last_processed_id' . $ruleGroupID . '_' . $groupID;
  $start = variable_get($startVariableName, 1);
  $batch_size = (integer) drush_get_option('batch', variable_get('wmf_civicrm_merge_batch_size', 1000));
  $end = CRM_Core_DAO::singleValueQuery("
    SELECT MAX(id)
    FROM (
      SELECT id FROM civicrm_contact WHERE id > %1 AND is_deleted = 0 ORDER BY id ASC LIMIT %2
    ) AS c",
    [1 => [$start, 'Integer'], 2 => [$batch_size, 'Integer']]
  );

  if (empty($end)) {
    Civi::log('wmf')->info('civicrm_merge: Deduping contact range from {start} - there are no more contacts to dedupe.', ['start' => $start]);
    return;
  }

  $mergeParams = array(
    'criteria' => array(
      'contact' => array('id' => array('BETWEEN' => array($start, $end))),
    ),
    // Use a zero limit as we are limiting by id range.
    // per https://github.com/civicrm/civicrm-core/pull/15185
    'search_limit' => 0,
  );
  if ($groupID) {
    $mergeParams['gid'] = $groupID;
  }
  if ($ruleGroupID) {
    $mergeParams['rule_group_id'] = $ruleGroupID;
  }

  \Civi::log('wmf')->info('civicrm_merge: Deduping contact range: {start} to {end}', ['start' => $start, 'end' => $end]);
  $result = civicrm_api3('Job', 'process_batch_merge', $mergeParams);

  variable_set($startVariableName, $end);
  \Civi::log('wmf')->info('civicrm_merge: Deduped contact range: {start} to {end}', ['start' => $start, 'end' => $end]);
  drush_print("Processed id range $start to " . $end . '
  Merged ' . count($result['values']['merged']) . ". Skipped " . count($result['values']['skipped']));
}

<?php

/**
 * Implementation of hook_drush_command()
 */
function civicrm_fix_mailing_data_drush_command(): array {
  return [
    'civicrm-fix-mailing-data' => [
      'description' => 'One off cleanup of mailing data',
    ],
  ];
}

/**
 * Implementation of hook_drush_help().
 */
function civicrm_fix_mailing_data_drush_help($section) {
  if ($section === 'drush:civicrm-fix-mailing-data') {
    return dt( 'One off job to merge old mailing data into less mailing_job records');
  }
}

/**
 * Merge a batch of contacts.
 *
 * @throws \CiviCRM_API3_Exception
 */
function drush_civicrm_fix_mailing_data() {
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

  // There are over 1 million rows like this. The main goal of the work
  // at the moment is to get the hook working so this is a proxy for 'something
  // useful while we test'
  CRM_Core_DAO::executeQuery("
UPDATE  civicrm_mailing_event_queue queue
INNER JOIN civicrm_mailing_job j ON j.id = queue.job_id
  SET job_id = 1801679
WHERE j.mailing_id = 373 AND job_id <> 1801679
LIMIT 2000");

}

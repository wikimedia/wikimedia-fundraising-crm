<?php

/**
 * Implementation of hook_drush_command()
 */
function civicrm_cleanup_old_deleted_contacts_drush_command() {
  $items = [];
  $items['civicrm-cleanup-contacts'] = [
    'description' => 'Delete old deleted contacts',
    'options' => [
      'batch' => 'Batch size',
      'date_cutoff' => 'Last modified date to be before'
    ],
  ];

  return $items;
}

/**
 * Cleanup old deleted contacts.
 *
 * This is designed to fully delete deleted contacts (ie civicrm_contact.is_deleted = 1)
 * who were deleted a 'long time ago' & not touched since (based on modified_date).
 *
 * There are some open questions here which testing will help with
 *
 * 1) Will it be unreasonably slow using the api - I assume it cleans up better
 * 2) What is the impact on log table size ie we are creating new delete rows
 *  but OTOH the log tables are much more compact in their writing
 * - my proposal is to delete the first? half mil & see.
 *
 * I'm assuming that we can go back & delete from the log tables, identifying them by
 * log_action = delete in log_civicrm_contact since we are not expecting many other 'delete' action
 * rows to exist in that table. Perhaps we could also wait a bit longer before removing anything from the logs
 * out of caution....
 *
 * Bug: T245087
 *
 * @throws \CiviCRM_API3_Exception
 */
function drush_civicrm_cleanup_old_deleted_contacts_civicrm_cleanup_contacts() {
  module_invoke('civicrm', 'initialize');

  $batch_size = (integer) drush_get_option('batch', 1000);
  $contacts = civicrm_api3('Contact', 'get', [
    'is_deleted' => 1,
    'modified_date' => ['<' => drush_get_option('date_cutoff', '2017-01-01')],
    'options' => ['limit' => $batch_size, 'sort' => 'modified_date'],
    'return' => ['id', 'modified_date'],
  ])['values'];

  $modifiedDate = null;
  foreach ($contacts as $contactID => $contact) {
    try {
      civicrm_api3('Contact', 'delete', ['skip_undelete' => 1, 'id' => $contactID]);
      if (!$modifiedDate) {
        $modifiedDate = $contact['modified_date'];
      }
    } 
    catch (Exception $e) {
      $message = 'This contact id is not able to be deleted: ' . $contactID;
      drush_print($message);
      watchdog('civicrm_cleanup_logs', $message);
    }
  }
  $message = !$modifiedDate ? "No deleted contact found to delete" : "Deleted deleted contacts, most recent modified_date: " . $modifiedDate;
  drush_print($message);
  watchdog('civicrm_cleanup_logs', $message);
}

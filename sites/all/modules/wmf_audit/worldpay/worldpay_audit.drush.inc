<?php
/**
 * @file wmf_audit.drush.inc
 * Parses reconciliation files from worldpay, and adds any missing data to civi.
 * @author Katie Horn <khorn@wikimedia.org>
 */

/**
 * Implementation of hook_drush_command()
 */
function worldpay_audit_drush_command() {
  $items = array();

  $items['worldpay-audit'] = array(
    'description' =>
      'Worldpay Audit tool: Parses worldpay reconciliation files and inserts any missing transactions into the message queues.',
    'examples' => array(
      'drush worldpay-audit' => '# Run the audit and rebuild job',
    ),
    'options' => array(
      'run_all' => 'Batch search for all missing transactions across all available recon files, instead of just the top three',
      'test' => 'Test the audit and rebuild job, but do not generate stomp messages. No data will be changed.',
      'fakedb' => 'Fake the database information. This will cause the script to avoid looking up the actual contribution tracking id.',
      'makemissing' => 'Will reconstruct the un-rebuildable transactions found in the recon file, with default values. USE WITH CAUTION: Currently this prevents real data from entering the system if we ever get it.',
      'charlimit' => 'Will cause echoing to line break after the given number of characters',
      'verbose' => 'Verbose output',
      'no_queue' => 'Running in direct insert mode - no queue flooding',
      'recon_complete_count' => 'Number past which we should consider a recon file to be "complete", and move it out of the working dir.',
    ),
    'aliases' => array('wp_audit'),
  );

  return $items;
}

/**
 * Implementation of hook_drush_help()
 */
function worldpay_audit_drush_help($section) {
  switch ($section) {
    case 'worldpay-audit':
      return dt("Worldpay Audit tool: Parses worldpay reconciliation files and inserts any missing transactions into the message queues.");
  }
}

/**
 * Fires the 'wmf_audit_main' method with the appropriate parameters
 */
function drush_worldpay_audit() {

  $simple_opts = array(
    'test' => 'Running in test mode: No stomp messages will be sent',
    'fakedb' => 'Faking Database',
    'makemissing' => 'Making payments data for missing transactions',
    'run_all' => 'Running all recon files! This might take a while...',
    'verbose' => 'Outputting verbose.',
    'no_queue' => 'Running in direct insert mode',
    'recon_complete_count' => 'Number past which we should consider a recon file to be "complete", and move it out of the working dir.'
  );

  $args = array();

  //handle our defaults first...
  $args['test'] = variable_get('worldpay_audit_test_mode', WP_AUDIT_TEST_MODE);
  $args['no_queue'] = variable_get('wmf_audit_direct_insert', WMF_AUDIT_DIRECT_INSERT); //defaults to false at the UI level. Can't really set *to* false at the command line, though...
  //now override with the command line settings
  foreach ($simple_opts as $key => $message) {
    if (drush_get_option($key)) {
      echo "$message\n";
      $args[$key] = true;
    }
  }

  //now, params that come with values
  if (drush_get_option('charlimit')) {
    echo "Char limit of " . drush_get_option('charlimit') . " is in effect.\n";
    $args['charlimit'] = drush_get_option('charlimit');
  }
  //local default
  $args['recon_complete_count'] = 0;
  if (drush_get_option('recon_complete_count')) {
    echo "Moving recon files with less than " . drush_get_option('recon_complete_count') . " outstanding transactions.\n";
    $args['recon_complete_count'] = drush_get_option('recon_complete_count');
  }

  module_invoke('wmf_audit', 'main', 'worldpay', $args);

  $errors = drush_get_error_log();
  if (!empty($errors)) {
    echo "\n***ERRORS***";
    foreach ($errors as $error => $msgarray) {
      echo "\n$error: ";
      foreach ($msgarray as $count => $message) {
        echo "\n    $message";
      }
    }
    echo "\n\n";
    exit(drush_get_error());
  }
}

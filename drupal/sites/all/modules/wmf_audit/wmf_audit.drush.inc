<?php
/**
 * @file wmf_audit.drush.inc
 * Parses reconciliation files and adds any missing data to civi.
 * @author Katie Horn <khorn@wikimedia.org>
 */

/**
 * Implementation of hook_drush_command()
 */
function wmf_audit_drush_command() {
  $items = [];

  $items['parse-audit'] = [
    'description' =>
      'Audit parsing tool: Parses reconciliation files and inserts any missing transactions into the message queues.',
    'examples' => [
      'drush parse-audit worldpay' => '# Run the audit for Worldpay and rebuild job',
    ],
    'arguments' => [
      'gateway' => 'Gateway name to process',
    ],
    'required-arguments' => TRUE,
    'options' => [
      'run_all' => 'Batch search for all missing transactions across all available recon files, instead of just the top three',
      'test' => 'Test the audit and rebuild job, but do not generate messages. No data will be changed.',
      'fakedb' => 'Fake the database information. This will cause the script to avoid looking up the actual contribution tracking id.',
      'makemissing' => 'Will reconstruct the un-rebuildable transactions found in the recon file, with default values. USE WITH CAUTION: Currently this prevents real data from entering the system if we ever get it.',
      'charlimit' => 'Will cause echoing to line break after the given number of characters',
      'verbose' => 'Verbose output',
      'recon_complete_count' => 'Number past which we should consider a recon file to be "complete", and move it out of the working dir.',
      'file' => 'Optional, name of a file to parse (must be in the incoming directory, should not include full path)'
    ],
  ];

  return $items;
}

/**
 * Implementation of hook_drush_help()
 */
function wmf_audit_drush_help($section) {
  switch ($section) {
    case 'parse-audit':
      return dt("Audit parsing tool: Parses reconciliation files and inserts any missing transactions into the message queues.");
  }
}

/**
 * Fires the 'wmf_audit_main' method with the appropriate parameters
 */
function drush_wmf_audit_parse_audit() {

  $args = drush_get_arguments();
  $gateway = $args[1]; // required-arguments should ensure this exists

  $simple_opts = [
    'test' => 'Running in test mode: No queue messages will be sent',
    'fakedb' => 'Faking Database',
    'makemissing' => 'Making payments data for missing transactions',
    'run_all' => 'Running all recon files! This might take a while...',
    'verbose' => 'Outputting verbose.',
    'recon_complete_count' => 'Number past which we should consider a recon file to be "complete", and move it out of the working dir.',
    'file' => 'File argument given, only processing specified file.'
  ];

  $options = [];

  //handle our defaults first...
  $options['test'] = variable_get('wmf_audit_test_mode') || variable_get("{$gateway}_audit_test_mode");
  //now override with the command line settings
  foreach ($simple_opts as $key => $message) {
    $value = drush_get_option($key);
    if ($value) {
      echo "$message\n";
      $options[$key] = $value;
    }
  }

  //now, params that come with values
  if (drush_get_option('charlimit')) {
    echo "Char limit of " . drush_get_option('charlimit') . " is in effect.\n";
    $options['charlimit'] = drush_get_option('charlimit');
  }
  //local default
  $options['recon_complete_count'] = 0;
  if (drush_get_option('recon_complete_count')) {
    echo "Moving recon files with less than " . drush_get_option('recon_complete_count') . " outstanding transactions.\n";
    $options['recon_complete_count'] = drush_get_option('recon_complete_count');
  }

  wmf_audit_main($gateway, $options);
}

<?php
/**
 * @file queue_consume.drush.inc
 *  Consume and process items from a message queue into CiviCRM
 * @author Arthur Richards <arichards@wikimedia.org>
 * @TODO print some useful info to STDOUT
 */

use SmashPig\Core\UtcDate;

/**
 * Implementation of hook_drush_command()
 */
function queue_consume_drush_command() {
  $items = [];

  $items['queue-consume'] = [
    'description' =>
      'Consumes items from a specified queue and processes them into CiviCRM',
    'examples' => [
      'drush queue-consume' => '# Consume the queue',
      'drush qc --batch=10' => '# Process up to 10 contributions',
    ],
    'options' => [
      'batch' => 'Number of contributions to process. If set to zero, it will be treated as unlimited',
    ],
    'aliases' => ['qc'],
  ];
  $items['donations-process-messagefile'] = [
    'description' =>
      'Feeds messages directly into the import pipeline, bypassing the queue.',
    'arguments' => [
      'path' => 'The JSON file containing the message(s)',
    ],
    'required-arguments' => TRUE,
    'examples' => ['drush dpm /tmp/blurr.json' => '# process the file'],
    'aliases' => ['dpm'],
  ];
  $items['queue-log-trim'] = [
    'description' =>
      'Trims the queue2civicrm_log table, deleting messages older than N days',
    'options' => [
      'days' => 'Maximum age in days of messages to keep',
    ],
    'examples' => ['drush qlt --days=60' => '# delete queue2civicrm_log entries older than 60 days'],
    'aliases' => ['qlt'],
  ];
  return $items;
}

/**
 * Implementation of hook_drush_help()
 */
function queue_consume_drush_help($section) {
  switch ($section) {
    case 'drush:queue-consume':
      return dt("Pulls items from a message queue and processes them into CiviCRM");
    case 'drush:donations-process-messagefile':
      return dt("Feeds messages directly into the import pipeline, bypassing the queue.");
  }
}


function drush_queue_consume_validate() {
  $batch = drush_get_option('batch');
  if (!empty ($batch)) {
    if (!is_numeric($batch)) {
      $message = '--batch must be a number';
      drush_set_error('QUEUE_CONSUMER_BATCH', dt($message));
      return FALSE;
    }
    if ($batch < 0) {
      $message = 'You specified a negative batch number, please use 0 or above';
      drush_set_error('QUEUE_CONSUMER_BATCH', dt($message));
      return FALSE;
    }
  }
  return TRUE;
}

/**
 * Fires the 'batch_process' method in the queue2civicrm module.
 *
 * This simply executes the code necessary to pull and process items from
 * a queue.  All configurations happen in the module.
 */
function drush_queue_consume() {
  // Set the number of contributions to batch
  $batch = drush_get_option('batch');
  $options = [];
  if (!empty($batch)) {
    $options['batch'] = $batch;
  }

  module_invoke('queue2civicrm', 'batch_process', $options);
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

/**
 * @param $path string Full path to a JSON file containing messages to import
 *
 * @throws \WmfException
 * @throws \Exception
 */
function drush_queue_consume_donations_process_messagefile($path) {
  watchdog('queue2civicrm', "Processing input file @path and feeding to queue2civicrm_import.",
    ['@path' => realpath($path)], WATCHDOG_INFO);
  $contents = file_get_contents($path);
  $messages = json_decode($contents, TRUE);
  if (!is_array($messages)) {
    throw new Exception("Error decoding JSON in '$path'.");
  }
  if (!isset($messages[0]) || !is_array($messages[0])) {
    $messages = [$messages];
  }
  foreach ($messages as $msg) {
    // FIXME: this bypasses pending database handling. Currently only good
    // for inserting test data that has no matching pending messages.
    // Could call DonationQueueConsumer::processMessage
    wmf_civicrm_contribution_message_import($msg);
  }
}

function drush_queue_consume_queue_log_trim() {
  $days = drush_get_option('days', 90);
  $timestamp = UtcDate::getUtcTimestamp("-$days days");
  drush_print("Deleting queue2civicrm_log messages older than $days days (before timestamp $timestamp).");
  $result = db_query("DELETE FROM queue2civicrm_log WHERE timestamp < $timestamp");
  $affected = $result->rowCount();
  drush_print("Deleted $affected rows from queue2civicrm.log.");
}

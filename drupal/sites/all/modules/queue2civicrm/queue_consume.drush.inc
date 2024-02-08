<?php
/**
 * @file queue_consume.drush.inc
 *  Consume and process items from a message queue into CiviCRM
 * @author Arthur Richards <arichards@wikimedia.org>
 * @TODO print some useful info to STDOUT
 */

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
      'drush qc --message-limit=10' => '# Process up to 10 contributions',
      'drush qc --time-limit=59 --wait=1' => '# Wait for new messages, till 59 seconds have passed',
    ],
    'options' => [
      'message-limit' => 'Number of contributions to process. If set to zero, it will be treated as unlimited',
      'time-limit' => 'Time limit in seconds for job',
      'wait' => 'When true, wait for new messages rather than exiting when the queue is empty'
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
  $messageLimit = drush_get_option('message-limit');
  if (!empty ($messageLimit)) {
    if (!is_numeric($messageLimit)) {
      $message = '--message-limit must be a number';
      drush_set_error('QUEUE_CONSUMER_MESSAGE_LIMIT', dt($message));
      return FALSE;
    }
    if ($messageLimit < 0) {
      $message = 'You specified a negative message limit, please use 0 or above';
      drush_set_error('QUEUE_CONSUMER_MESSAGE_LIMIT', dt($message));
      return FALSE;
    }
  }
  if (drush_get_option('wait')) {
    if (empty($messageLimit) && empty(drush_get_option('time-limit'))) {
      $message = 'You need to specify --message-limit or --time-limit to use --wait';
      drush_set_error('QUEUE_CONSUMER_MESSAGE_LIMIT', dt($message));
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
  $messageLimit = drush_get_option('message-limit');
  $timeLimit = drush_get_option('time-limit');
  $wait = drush_get_option('wait', FALSE);

  module_invoke('queue2civicrm', 'batch_process', $messageLimit, $timeLimit, $wait);
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
 * @throws \Civi\WMFException\WMFException
 * @throws \Exception
 */
function drush_queue_consume_donations_process_messagefile($path) {
  civicrm_initialize();
  \Civi::log('wmf')->info('queue2civicrm: Processing input file {path} and feeding to queue2civicrm_import.',
    ['path' => realpath($path)]);
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

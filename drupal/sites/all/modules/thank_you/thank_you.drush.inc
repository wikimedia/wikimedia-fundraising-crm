<?php

/**
 * Implementation of hook_drush_command()
 */
function thank_you_drush_command() {
  $items = [];

  $items['thank-you-send'] = [
    'description' =>
      'Sends thank you emails for donations',
    'examples' => ['drush thank-you' => '# Send thank you emails'],
    'aliases' => ['ty'],
    'options' => [
      'message-limit' => 'Number of messages to attempt to send',
      'days' => 'Number of days to parse',
      'time-limit' => 'Time limit for job',
    ],
  ];

  return $items;
}

/**
 * Implementation of hook_drush_help()
 */
function thank_you_drush_help($section) {
  switch ($section) {
    case 'drush:thank-you-send':
      return dt("Send thank you emails for donations ");
  }
}

/**
 * Fires the 'batch_process' method in the thank_you module.
 *
 * This simply executes the code necessary to run the thank you modules.
 * All configuration happens in the module.
 */
function drush_thank_you_send() {
  module_invoke('thank_you', 'batch_process',
    drush_get_option('message-limit'),
    drush_get_option('days'),
    drush_get_option('time-limit')
  );
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


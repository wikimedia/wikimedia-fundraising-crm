<?php

/**
 * Implementation of hook_drush_command()
 */
function ct_queue_consume_drush_command() {
  $items = [];

  $items['ct-queue-consume'] = [
    'description' => 'Populates the contribution tracking database with messages it pops off of the contribution-tracking queue',
    'examples' => [
      'drush ct-queue-consume' => 'Shovels messages straight into the database.',
    ],
    'aliases' => ['ctqc'],
  ];
  return $items;
}

/**
 * Implementation of hook_drush_help()
 *
 * @param $section
 *
 * @return mixed
 */
function ct_queue_consume_drush_help($section) {
  switch ($section) {
    case 'drush:ct-queue-consume':
      return dt("Pulls contribution tracking messages from a remote queue then saves them to the contribution tracking db table");
  }
}

/**
 * Calls queue2civicrm/contribution_tracking/wmf_ct_qc.module:ct_batch_process()
 */
function drush_ct_queue_consume() {
  watchdog('contribution_tracking', 'Executing: drush_ct_queue_consume');
  module_invoke('ct', 'batch_process');
  $errors = drush_get_error_log();
  if (!empty($errors)) {
    echo "\n***ERRORS***";
    foreach ($errors as $error => $msgarray) {
      echo "\n$error: ";
      foreach ($msgarray as $count => $message) {
        echo "\n	  $message";
      }
    }
    echo "\n\n";
    exit(drush_get_error());
  }
}


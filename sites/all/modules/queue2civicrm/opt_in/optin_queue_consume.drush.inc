<?php
/**
 * @file optin_queue_consume.drush.inc
 *
 * Pulls optin requests from a remote queue and processes them
 *
 * @author Matt Walker <mwalker@wikimedia.org>
 */

/**
 * Implementation of hook_drush_command()
 */
function optin_queue_consume_drush_command() {
  $items = [];

  $items['optin-queue-consume'] = [
    'description' => 'Pulls optin requests from a remote queue and processes them',
    'examples' => [
      'drush optin-queue-consume' => 'Pulls a default number of optin messages',
    ],
    'aliases' => ['oqc'],
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
function optin_queue_consume_drush_help($section) {
  switch ($section) {
    case 'drush:optin-queue-consume':
      return dt("Pulls optin requests from a remote queue; then uses the Civi API to opt them out.");
  }
}

/**
 * Generates translated versions of the optin pages from the
 * DonationInterface i18n files.
 */
function drush_optin_queue_consume() {
  watchdog('optin', 'Executing: drush_optin_queue_consume');
  module_invoke('optin', 'batch_process');
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


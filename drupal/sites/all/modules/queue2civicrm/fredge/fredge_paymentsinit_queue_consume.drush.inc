<?php
/**
 * @file fredge_paymentsinit_queue_consume.drush.inc
 *
 * Pops interesting fundraising metric data off of whatever queue and stuffs it
 * into the fredge database for later analysis.
 *
 * @author Katie Horn <khorn@wikimedia.org>
 */

/**
 * Implementation of hook_drush_command()
 */
function fredge_paymentsinit_queue_consume_drush_command() {
  $items = [];

  $items['fredge-paymentsinit-queue-consume'] = [
    'description' => 'Populates the fredge.paymentsinit database with messages it pops off of the queue',
    'examples' => [
      'drush fredge-paymentsinit-queue-consume' => 'Shovels messages straight into the database.',
    ],
    'aliases' => ['piqc'],
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
function fredge_paymentsinit_queue_consume_drush_help($section) {
  switch ($section) {
    case 'drush:fredge-paymentsinit-queue-consume':
      return dt("Pulls fredge requests from a remote queue; then uses the Civi API to opt them out.");
  }
}

function drush_fredge_paymentsinit_queue_consume() {
  watchdog('fredge', 'Executing: drush_fredge_paymentsinit_queue_consume');
  module_invoke('fredge', 'paymentsinit_batch_process');
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
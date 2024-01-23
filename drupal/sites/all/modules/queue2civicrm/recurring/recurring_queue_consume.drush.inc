<?php

/**
 * Implementation of hook_drush_command()
 */
function recurring_queue_consume_drush_command() {
  $items = [];

  $items['recurring-queue-consume'] = [
    'description' =>
      'Consumes items from a specified message queue and processes them into CiviCRM',
    'examples' => ['drush recurring-queue-consume' => '# Consume the queue'],
    'options' => [
      'queue' => 'Defaults to recurring, but can also read from recurring-upgrade',
    ],
    'aliases' => ['rqc'],
  ];

  return $items;
}

/**
 * Implementation of hook_drush_help()
 */
function recurring_queue_consume_drush_help($section) {
  switch ($section) {
    case 'drush:recurring-queue-consume':
      return dt("Pulls recurring items from a message queue and processes them into CiviCRM");
  }
}

/**
 * Fires the 'batch_process' method in the recurring module.
 *
 * This simply executes the code necessary to pull and process items from
 * a queue.  All configurations happen in the module.
 */
function drush_recurring_queue_consume() {
  $queue = drush_get_option('queue', 'recurring');
  module_invoke('recurring', 'batch_process', $queue);
}

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
    'aliases' => ['rqc'],
  ];
  $items['recurring-process-messagefile'] = [
    'description' =>
      'Feeds a single message directly into the recurring import pipeline, bypassing the queue.',
    'arguments' => [
      'path' => 'The JSON file containing the message',
    ],
    'required-arguments' => TRUE,
    'examples' => ['drush rpm /tmp/blurr.json' => '# process the file'],
    'aliases' => ['rpm'],
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
    case 'drush:recurring-process-messagefile':
      return dt("Feeds a single message directly into the recurring import pipeline, bypassing the queue.");
  }
}

/**
 * Fires the 'batch_process' method in the recurring module.
 *
 * This simply executes the code necessary to pull and process items from
 * a queue.  All configurations happen in the module.
 */
function drush_recurring_queue_consume() {
  module_invoke('recurring', 'batch_process');
}

function drush_recurring_queue_consume_recurring_process_messagefile($path) {
  civicrm_initialize();
  \Civi::log('wmf')
    ->info('recurring: Processing input file {path} and feeding to recurring_import.',
      ['path' => realpath($path)]
    );
  $contents = file_get_contents($path);
  $msg = json_decode($contents, TRUE);
  module_invoke('recurring', 'import', $msg);
}

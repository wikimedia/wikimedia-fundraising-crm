<?php

/**
 * Implementation of hook_drush_command()
 */
function recurring_queue_consume_drush_command() {
  $items = array();

  $items['recurring-queue-consume'] = array(
    'description' => 
      'Consumes items from a specified message queue and processes them into CiviCRM',
    'examples' => array( 'drush recurring-queue-consume' => '# Consume the queue' ), 
    'aliases' => array( 'rqc' ),
  );
  $items['recurring-process-messagefile'] = array(
    'description' => 
      'Feeds a single message directly into the recurring import pipeline, bypassing the queue.',
    'arguments' => array(
        'path' => 'The JSON file containing the message'
    ),
    'required-arguments' => true,
    'examples' => array( 'drush rpm /tmp/blurr.json' => '# process the file' ), 
    'aliases' => array( 'rpm' ),
  );
 

  return $items;
}

/**
 * Implementation of hook_drush_help()
 */
function recurring_queue_consume_drush_help( $section ) {
  switch ( $section ) {
    case 'drush:recurring-queue-consume':
      return dt( "Pulls recurring items from a message queue and processes them into CiviCRM" );
    case 'drush:recurring-process-messagefile':
      return dt( "Feeds a single message directly into the recurring import pipeline, bypassing the queue." );
  }
}

/**
 * Fires the 'batch_process' method in the recurring module.
 *
 * This simply executes the code necessary to pull and process items from 
 * a queue.  All configurations happen in the module.
 */
function drush_recurring_queue_consume() {
  module_invoke( 'recurring', 'batch_process' );
}

function drush_recurring_queue_consume_recurring_process_messagefile( $path ) {
	civicrm_initialize();
    watchdog( 'recurring', "Processing input file @path and feeding to recurring_import.",
        array( '@path' => realpath( $path ) ), WATCHDOG_INFO );
    $contents = file_get_contents( $path );
    $msg = json_decode( $contents, true );
    module_invoke( 'recurring', 'import', $msg );
}

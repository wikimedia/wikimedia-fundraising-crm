<?php
/**
 * @file queue_consume.drush.inc
 *  Consume and process items from an ActiveMQ queue into CiviCRM
 * @author Arthur Richards <arichards@wikimedia.org>
 * @TODO print some useful info to STDOUT
 */

/**
 * Implementation of hook_drush_command()
 */
function queue_consume_drush_command() {
  $items = array();

  $items['queue-consume'] = array(
    'description' => 
      'Consumes items from a specified ActiveMQ queue and processes them into CiviCRM',
    'examples' => array( 'drush queue-consume' => '# Consume the queue' ), 
    'aliases' => array( 'qc' ),
  );
  $items['donations-process-messagefile'] = array(
    'description' => 
      'Feeds a single message directly into the import pipeline, bypassing the queue.',
    'arguments' => array(
        'path' => 'The JSON file containing the message'
    ),
    'required-arguments' => true,
    'examples' => array( 'drush dpm /tmp/blurr.json' => '# process the file' ), 
    'aliases' => array( 'dpm' ),
  );

  return $items;
}

/**
 * Implementation of hook_drush_help()
 */
function queue_consume_drush_help( $section ) {
  switch ( $section ) {
    case 'drush:queue-consume':
      return dt( "Pulls items from an ActiveMQ queue and processes them into CiviCRM" );
    case 'drush:donations-process-messagefile':
      return dt( "Feeds a single message directly into the import pipeline, bypassing the queue." );
  }
}

/**
 * Fires the 'batch_process' method in the queue2civicrm module.
 *
 * This simply executes the code necessary to pull and process items from 
 * a queue.  All configurations happen in the module.
 */
function drush_queue_consume() {
  module_invoke( 'queue2civicrm', 'batch_process' );
  $errors = drush_get_error_log();
  if (!empty($errors)){
	  echo "\n***ERRORS***";
	  foreach($errors as $error=>$msgarray){
		  echo "\n$error: ";
		  foreach ($msgarray as $count=>$message){
			  echo "\n    $message";
		  }
	  }
	  echo "\n\n";
	  exit(drush_get_error());
  }
}

function drush_queue_consume_donations_process_messagefile( $path ) {
    watchdog( 'queue2civicrm', "Processing input file @path and feeding to queue2civicrm_import.",
        array( '@path' => realpath( $path ) ), WATCHDOG_INFO );
    $contents = file_get_contents( $path );
    $msg = json_decode( $contents, true );
    if ($msg === NULL) {
      throw new Exception("Error decoding JSON in '$path'.");
    }
    module_invoke( 'queue2civicrm', 'import', $msg );
}

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
  $items = array();

  $items['queue-consume'] = array(
    'description' => 
      'Consumes items from a specified queue and processes them into CiviCRM',
    'examples' => array( 'drush queue-consume' => '# Consume the queue' ), 
    'aliases' => array( 'qc' ),
  );
  $items['donations-process-messagefile'] = array(
    'description' => 
      'Feeds messages directly into the import pipeline, bypassing the queue.',
    'arguments' => array(
        'path' => 'The JSON file containing the message(s)'
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
      return dt( "Pulls items from a message queue and processes them into CiviCRM" );
    case 'drush:donations-process-messagefile':
      return dt( "Feeds messages directly into the import pipeline, bypassing the queue." );
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
    $messages = json_decode( $contents, true );
    if ( !is_array( $messages ) ) {
      throw new Exception("Error decoding JSON in '$path'.");
    }
    if ( !isset( $messages[0] ) || !is_array( $messages[0] ) ) {
      $messages = array( $messages );
    }
    foreach( $messages as $msg ) {
    	// FIXME: this bypasses pending database handling. Currently only good
		// for inserting test data that has no matching pending messages.
		// Could call DonationQueueConsumer::processMessage
		wmf_civicrm_contribution_message_import( $msg );
    }
}

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

  return $items;
}

/**
 * Implementation of hook_drush_help()
 */
function queue_consume_drush_help( $section ) {
  switch ( $section ) {
    case 'drush:queue-consume':
      return dt( "Pulls items from an ActiveMQ queue and processes them into CiviCRM" );
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

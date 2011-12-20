<?php
/**
 * @file limbo_queue_consume.drush.inc
 *	Consume and process items from an ActiveMQ limbo queue into CiviCRM
 * @author Arthur Richards <arichards@wikimedia.org>
 * @TODO print some useful info to STDOUT
 */

/**
 * Implementation of hook_drush_command()
 */
function limbo_queue_consume_drush_command() {
  $items = array();

  $items['limbo-queue-consume'] = array(
	'description' => 
	  'Consumes items from a specified ActiveMQ limbo queue and processes them into CiviCRM',
	'examples' => array( 'drush limbo-queue-consume' => '# Consume the limbo queue' ), 
	'aliases' => array( 'lqc' ),
  );

  return $items;
}

/**													  
 * Implementation of hook_drush_help()
 */
function limbo_queue_consume_drush_help( $section ) {

  //watchdog('limbo', 'Someone needs help with limbo \0/');

  switch ( $section ) {
	case 'drush:limbo-queue-consume':
	  return dt( "Pulls items from an ActiveMQ limbo queue and processes them into CiviCRM" );
  }
}

/**
 * Fires the 'batch_process' method in the queue2civicrm module.
 *
 * This simply executes the code necessary to pull and process items from 
 * a queue.	 All configurations happen in the module.
 */
function drush_limbo_queue_consume() {
  watchdog('limbo', 'Executing: drush_limbo_queue_consume');
  module_invoke( 'limbo', 'batch_process' );
  $errors = drush_get_error_log();
  if (!empty($errors)){
	  echo "\n***ERRORS***";
	  foreach($errors as $error=>$msgarray){
		  echo "\n$error: ";
		  foreach ($msgarray as $count=>$message){
			  echo "\n	  $message";
		  }
	  }
	  echo "\n\n";
	  exit(drush_get_error());
  }
}

<?php

/**
 * Implementation of hook_drush_command()
 */
function thank_you_drush_command() {
  $items = array();

  $items['thank-you-send'] = array(
    'description' => 
      'Sends thank you emails for donations',
    'examples' => array( 'drush thank-you' => '# Send thank you emails' ),
    'aliases' => array( 'ty' ),
  );

  $items['thank-you-send-test'] = array(
    'description' =>
      'Simulates sending 1000 thank you emails for donations, to test DB performance',
    'aliases' => array( 'tyt' ),
  );
  return $items;
}

/**
 * Implementation of hook_drush_help()
 */
function thank_you_drush_help( $section ) {
  switch ( $section ) {
    case 'drush:thank-you-send':
      return dt( "Send thank you emails for donations " );
  }
}

/**
 * Fires the 'batch_process' method in the thank_you module.
 *
 * This simply executes the code necessary to run the thank you modules.
 * All configuration happens in the module.
 */
function drush_thank_you_send() {
  module_invoke( 'thank_you', 'batch_process' );
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


/**
 * Fires the 'batch_process' method in the thank_you module.
 *
 * This simply executes the code necessary to run the thank you modules.
 * All configuration happens in the module.
 */
function drush_thank_you_send_test() {
	module_invoke( 'thank_you', 'batch_test' );
}

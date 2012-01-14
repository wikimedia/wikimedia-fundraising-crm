<?php
/**
 * @file globalcollact_audit.drush.inc
 *  Parses .wr1 files from globalcollect and inserts any missing transactions 
 * into the regular stomp message queue.
 * @author Katie Horn <khorn@wikimedia.org>
 * @TODO print some useful info to STDOUT
 */

/**
 * Implementation of hook_drush_command()
 */
function globalcollect_audit_drush_command() {
  $items = array();

  $items['globalcollect-audit'] = array(
    'description' => 
      'Globalcollect Audit tool: Parses .wr1 files from globalcollect and inserts any missing transactions into the regular stomp message queue.',
    'examples' => array( 'drush globalcollect-audit' => '# Parse the wr1 file' ), 
    'aliases' => array( 'gc_wr1' ),
  );

  return $items;
}

/**
 * Implementation of hook_drush_help()
 */
function globalcollect_audit_drush_help( $section ) {
  switch ( $section ) {
    case 'globalcollect-audit':
      return dt( "Parses .wr1 files from globalcollect and inserts any missing transactions into the regular stomp message queue." );
  }
}

/**
 * Fires the 'parse_all_wr1' method in the globalcollect_audit module.
 *
 * TODO: More comments here-ish. 
 */
function drush_globalcollect_audit() {
	module_invoke( 'globalcollect_audit', 'parse_all_wr1' );
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
	
	exit(drush_get_error());
}

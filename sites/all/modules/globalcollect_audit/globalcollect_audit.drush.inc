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
	$args = drush_get_arguments();
	$test_mode_orig = null;
	if ( count($args) ){
		while( $arg = array_shift($args) ){
			switch ( $arg ) {
				case 'test':
					//presumably we want to run in test mode. 
					echo "Running in test mode: No stomp messages will be sent\n";
					$test_mode_orig = variable_get( 'globalcollect_audit_test_mode', CONTRIBUTION_AUDIT_TEST_MODE );
					variable_set( 'globalcollect_audit_test_mode', true );
					break;
				case 'fakedb':
					echo "Faking Database\n";
					variable_set( 'globalcollect_audit_fake_db', true  );
			}
		}
	}
	
	module_invoke( 'globalcollect_audit', 'parse_all_wr1' );
	
	if ( !is_null( $test_mode_orig ) && $test_mode_orig === false ){
		variable_set( 'globalcollect_audit_test_mode', false );
	}
	
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

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
    'examples' => array( 
		'drush globalcollect-audit' => '# Parse the wr1 file',
		'drush gc_wr1 --test' => '# Test the audit scripts, but do not generate stomp messages. No data will be changed.',
		'drush gc_wr1 --fakedb' => '# Fake the database information. This will cause the script to avoid looking up the actual contribution id.',
		'drush gc_wr1 --makemissing' => '# Will reconstruct the un-rebuildable transactions found in the wr1, with default values. USE WITH CAUTION.',
		'drush gc_wr1 --run_all' => '# Batch search for all missing transactions across all wr1 files.',
		'drush gc_wr1 --charlimit=50' => '# Will cause echoing to line break after 50 characters',
		'drush gc_wr1 --mismatch_override' => 'Won\'t die on a keygrind mismatch',
		), 
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

function globalcollect_audit_reset_drush_vars(){
	static $vars = null;
	if ( is_null( $vars ) ){
		$vars = array(
			'globalcollect_audit_test_mode' => variable_get( 'globalcollect_audit_test_mode', CONTRIBUTION_AUDIT_TEST_MODE ),
			'globalcollect_audit_fake_db' => false,
			'globalcollect_audit_make_missing' => false,
			'globalcollect_audit_run_all_wr1' => false,
			'globalcollect_audit_charlimit' => 0,
			'globalcollect_audit_mismatch_override' => false,
		);
	}
	
	foreach ( $vars as $var => $default ){
		variable_set( $var, $default );
	}
}

/**
 * Fires the 'parse_all_wr1' method in the globalcollect_audit module.
 *
 * TODO: More comments here-ish. 
 */
function drush_globalcollect_audit() {
	//$args = drush_get_arguments();
	
	//if the last execution died before we got back here
	globalcollect_audit_reset_drush_vars();
	
	if (drush_get_option('test')){
		echo "Running in test mode: No stomp messages will be sent\n";
		variable_set( 'globalcollect_audit_test_mode', true );
	}
	if (drush_get_option('fakedb')){
		echo "Faking Database\n";
		variable_set( 'globalcollect_audit_fake_db', true  );
	}
	if (drush_get_option('makemissing')){
		echo "Making missing transactions---\n";
		variable_set( 'globalcollect_audit_make_missing', true  );
	}
	if (drush_get_option('run_all')){ 
		echo "Running all WR1 Files! This might take a while...\n";
		variable_set( 'globalcollect_audit_run_all_wr1', true );
	}
	if (drush_get_option('charlimit')){ 
		echo "Char limit of " . drush_get_option('charlimit') . " is in effect.\n";
		variable_set( 'globalcollect_audit_charlimit', drush_get_option('charlimit') );
	}
	if (drush_get_option('mismatch_override')){ 
		echo "Overriding keygrind mismatches. Problem records will not be fatal, but they will still be logged.\n";
		variable_set( 'globalcollect_audit_mismatch_override', true );
	}
	
	module_invoke( 'globalcollect_audit', 'parse_all_wr1' );
	
	//Be Kind: Rewind (your run settings).
	globalcollect_audit_reset_drush_vars();
	
	echo "\n***RESULTS***";
	$results = globalcollect_audit_results_ball();
	echo "\n" . print_r($results, true);
	
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

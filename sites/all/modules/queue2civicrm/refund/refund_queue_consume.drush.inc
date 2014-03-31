<?php
/**
 * @file refund_queue_consume.drush.inc
 *
 * Pulls refund requests from a remote queue; then uses the Civi API to associate
 * the refund with the appropriate contribution. If the contribution does not exist
 * the message will be requeued for later consumption.
 *
 * If the contribution was marked as a refund in the old method (amounts zeroed) this
 * function will attempt to reconstruct the original record.
 *
 * @author Matt Walker <mwalker@wikimedia.org>
 */

/**
 * Implementation of hook_drush_command()
 */
function refund_queue_consume_drush_command() {
  $items = array();

  $items['refund-queue-consume'] = array(
    'description' => 'Pulls refund requests from a remote queue; then uses the Civi API associate the refund appropriately.',
    'examples' => array(
      'drush rfdqc' => 'Pulls a default number of messages and marks the refunds',
    ),
    'aliases' => array( 'rfdqc' ),
  );
  $items['refund-process-messagefile'] = array(
    'description' => 
      'Feeds a single message directly into the refund pipeline, bypassing the queue.',
    'arguments' => array(
        'path' => 'The JSON file containing the message'
    ),
    'required-arguments' => true,
    'examples' => array( 'drush rfdpm /tmp/blurr.json' => '# process the file' ), 
    'aliases' => array( 'rfdpm' ),
  );
  return $items;
}

/**
 * Implementation of hook_drush_help()
 *
 * @param $section
 * @return mixed
 */
function refund_queue_consume_drush_help( $section ) {
  switch ( $section ) {
    case 'drush:refund-queue-consume':
      return dt( "Pulls refund requests from a remote queue; then uses the Civi API associate the refund appropriately.");
    case 'drush:refund-process-messagefile':
      return dt( "Feeds a single message directly into the refund pipeline, bypassing the queue." );
  }
}

/**
 * Generates translated versions of the unsubscribe pages from the
 * DonationInterface i18n files.
 */
function drush_refund_queue_consume() {
  watchdog('refund', 'Executing: drush_refund_queue_consume');
  module_invoke( 'refund', 'batch_process' );
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

function drush_refund_queue_consume_refund_process_messagefile( $path ) {
    watchdog( 'wmf_refund', "Processing input file @path and feeding to refund_import.",
        array( '@path' => realpath( $path ) ), WATCHDOG_INFO );
    $contents = file_get_contents( $path );
    $msg = json_decode( $contents, true );
    module_invoke( 'civicrm', 'initialize' );
    module_invoke( 'refund', 'import', $msg );
}

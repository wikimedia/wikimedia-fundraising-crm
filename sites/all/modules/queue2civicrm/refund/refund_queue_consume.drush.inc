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


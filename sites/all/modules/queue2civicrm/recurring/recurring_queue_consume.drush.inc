<?php

/**
 * Implementation of hook_drush_command()
 */
function recurring_queue_consume_drush_command() {
  $items = array();

  $items['recurring-queue-consume'] = array(
    'description' => 
      'Consumes items from a specified ActiveMQ queue and processes them into CiviCRM',
    'examples' => array( 'drush recurring-queue-consume' => '# Consume the queue' ), 
    'aliases' => array( 'rqc' ),
  );

  return $items;
}

/**
 * Implementation of hook_drush_help()
 */
function recurring_queue_consume_drush_help( $section ) {
  switch ( $section ) {
    case 'drush:recurring-queue-consume':
      return dt( "Pulls recurring items from an ActiveMQ queue and processes them into CiviCRM" );
  }
}

/**
 * Fires the 'batch_process' method in the recurring module.
 *
 * This simply executes the code necessary to pull and process items from 
 * a queue.  All configurations happen in the module.
 */
function drush_recurring_queue_consume() {
  module_invoke( 'recurring', 'batch_process' );
}

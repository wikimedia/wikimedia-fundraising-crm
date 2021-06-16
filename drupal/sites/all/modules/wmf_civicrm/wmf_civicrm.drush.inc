<?php

use Civi\WMFException\WMFException;
/**
 * Implementation of hook_drush_command()
 */
function wmf_civicrm_drush_command() {
  $items['recalculate-contributions'] = [
    'description' => 'Recalculates non-USD contributions over a date range based on contents of exchange_rates table.',
    'examples' => [
      'drush recalculate-contributions 2014-06-20 2014-09-30',
    ],
    'arguments' => [
      'start' => 'Date of earliest contributions to recalculate in YYYY-MM-DD format',
      'end' => 'Date of latest contributions to recalculate in YYYY-MM-DD format',
    ],
    'required-arguments' => TRUE,
    'aliases' => ['recalc'],
  ];

  $items['update-custom-fields'] = [
    'description' => 'Update/sync civicrm custom fields',
    'examples' => [
      'drush ucf',
    ],
    'aliases' => ['ucf'],
  ];
  return $items;
}

/**
 * Implementation of hook_drush_help()
 */
function wmf_civicrm_drush_help($section) {
  switch ($section) {
    case 'drush:wmf-recalculate-contributions':
      return dt(
        'Recalculates USD amounts of non-USD contributions over a given range.  Requires two arguments, start and end dates in YYYY-MM-DD format.'
      );
    case 'drush:wmf-update-custom-fields':
      return dt('Runs the update-custom-civicrm-fields process');
  }
}

function drush_wmf_civicrm_recalculate_contributions() {
	$args = drush_get_arguments();
	if ( count( $args ) !== 3 ) {
		throw new WMFException( WMFException::UNKNOWN, "{$args[0]} requires a start and an end date." );
	}
	$start_string = $args[1];
	$end_string = $args[2];
	$start = new DateTime( $start_string, new DateTimeZone( 'UTC' ) );
	$end = new DateTime( $end_string, new DateTimeZone( 'UTC' ) );
	module_invoke( 'wmf_civicrm', 'recalculate_contributions', $start, $end );
}
function drush_wmf_civicrm_update_custom_fields() {
	module_invoke( 'wmf_civicrm', 'update_custom_fields');
}

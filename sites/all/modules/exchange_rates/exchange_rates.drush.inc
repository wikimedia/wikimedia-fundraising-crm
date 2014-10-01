<?php

/**
 * Implementation of hook_drush_command()
 */
function exchange_rates_drush_command() {
	$items['exchange-rates-update'] = array(
		'description' => 'Updates exchange rates from provider.',
		'examples' => array( 'drush exchange-rates-update' ),
	);
	$items['exchange-rates-backfill'] = array(
		'description' => 'Retrieves and stores daily exchange rates for a date range.',
		'examples' => array(
			'drush exchange-rates-backfill',
			'drush exchange-rates-backfill --start=2014-06-20'
		),
		'options' => array(
			'start' => 'Date of earliest rates to request in YYYY-MM-DD format',
			'end' => 'Date of latest rates to request in YYYY-MM-DD format',
			'recalculate' => 'Whether to recalculate contributions from the same range'
		)
	);
	return $items;
}

/**
 * Implementation of hook_drush_help()
 */
function exchange_rates_drush_help( $section ) {
	switch ( $section ) {
	case 'drush:exchange-rates-update':
		return dt( 'Attempts to retrieve the latest exchange rates from a providers, stopping when a request succeeds and storing results in the database.' );
	case 'drush:exchange-rates-backfill':
		return dt( 'Backfills daily exchanges rates across a range of dates.  --start and --end parameters take a date in YYYY-MM-DD format.  If run with no parameters, will backfill starting from last updated date.  When run with --recalculate=true, will recalculate all non-USD contributions in the same range.' );
	}
}

function drush_exchange_rates_update() {
	module_invoke( 'exchange_rates', 'update_all' );
}

function drush_exchange_rates_backfill() {
	$start_string = drush_get_option( 'start' );
	$end_string = drush_get_option( 'end' );
	$recalculate = filter_var( drush_get_option( 'recalculate', false ), FILTER_VALIDATE_BOOLEAN );
	$start = $start_string ? new DateTime( $start_string, new DateTimeZone( 'UTC' ) ) : null;
	$end = $end_string ? new DateTime( $end_string, new DateTimeZone( 'UTC' ) ) : null;
	module_invoke( 'exchange_rates', 'backfill', $start, $end, $recalculate );
}

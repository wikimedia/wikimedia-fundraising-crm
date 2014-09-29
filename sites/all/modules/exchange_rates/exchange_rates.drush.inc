<?php

/**
 * Implementation of hook_drush_command()
 */
function exchange_rates_drush_command() {
	$items['exchange-rates-update'] = array(
		'description' => 'Updates exchange rates from provider.',
		'examples' => array( 'drush exchange-rates-update' ),
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
	}
}

function drush_exchange_rates_update() {
	module_invoke( 'exchange_rates', 'update_all' );
}

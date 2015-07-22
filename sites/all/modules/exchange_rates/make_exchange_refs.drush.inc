<?php

use wmf_communication\Templating;

/**
 * Implementation of hook_drush_command()
 */
function make_exchange_refs_drush_command() {
    $items['make-exchange-refs'] = array(
        'description' => 'Generate CurrencyRates.php static exchange rate class.',
        'examples' => array( 'drush make-exchange-refs' ),
    );
    return $items;
}

/**
 * Implementation of hook_drush_help()
 */
function make_exchange_refs_drush_help( $section ) {
    switch ( $section ) {
    case 'drush:make-exchange-refs':
        return dt( 'Generate reference tables of the currency exchange rates, in multiple output languages.  Arguments are output files, whose format is determined by file extension.' );
    }
}

function drush_make_exchange_refs() {

    $rates = module_invoke( 'exchange_rates', 'get_conversions' );
    $dir = __DIR__ . '/generated/';
	if ( !is_dir( $dir ) ) {
		mkdir( $dir );
	}
    file_put_contents(
        $dir . 'CurrencyRates.php',
        exchange_rates_make_ref_content( 'php', $rates )
    );
}

/* protected */
function exchange_rates_make_ref_content( $format, $rates ) {
	$inverse = array_map( 'exchange_rates_invert_and_round', $rates );
    $twig = Templating::twig_from_directory( __DIR__ . '/templates' );

	$filename = "ref_source.{$format}.twig";

    $params = array(
        'generated_by' => basename( __FILE__ ) . ":templates/{$filename}",
        'last_updated' => date( 'Y-m-d', variable_get( 'exchange_rates_bank_update', 0 ) ),
        'currency_rates' => $inverse,
    );

    return $twig->render( $filename, $params );
}
function exchange_rates_invert_and_round( $rate ) {
	$value_in_usd = $rate[1];
	if ( $value_in_usd === 0 ) {
		return $rate;
	}
	$units_in_one_dollar = 1.0 / $value_in_usd;
	if ( $units_in_one_dollar > 10 ) {
		$rate[1] = round( $units_in_one_dollar );
	} else if ( $units_in_one_dollar > 1 ) {
		$rate[1] = round( $units_in_one_dollar, 2 );
	} else {
		$rate[1] = $units_in_one_dollar;
	}
	return $rate;
}

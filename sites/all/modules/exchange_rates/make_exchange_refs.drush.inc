<?php
/**
 * Implementation of hook_drush_command()
 */
function make_exchange_refs_drush_command() {
    $items['make-exchange-refs'] = array(
        'description' => 'Generate reference tables of the currency exchange rates, in multiple output formats.',
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
    $dir = __DIR__ . "/generated/";
    file_put_contents(
        $dir . "ExchangeRates.html",
        exchange_rates_make_ref_content( 'html', $rates )
    );
    file_put_contents(
        $dir . "ExchangeRates.php",
        exchange_rates_make_ref_content( 'php', $rates )
    );
    foreach ( $rates as $info ) {
        list ( $currency, $rate ) = $info;
        //TODO: a more compact template for this case
        file_put_contents(
            $dir . "ExchangeRates_{$currency}.html",
            exchange_rates_make_ref_content( 'html', array( $info ) )
        );
    }
}

/* protected */
function exchange_rates_make_ref_content( $format, $rates ) {
    static $twig = null;

    if ( !$twig ) {
        module_load_include( 'inc', 'wmf_common', 'twig' );
        $twig = wmf_common_get_twig( __DIR__ . "/templates" );
    }

    $filename = "ref_source.{$format}.twig";
    return $twig->render( $filename, array(
        'generated_by' => basename( __FILE__ ) . ":templates/{$filename}",
        'last_updated' => variable_get( 'exchange_rates_bank_update', 0 ),
        'currency_rates' => $rates,
    ) );
}

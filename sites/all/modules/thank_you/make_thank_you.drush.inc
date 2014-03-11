<?php

function make_thank_you_drush_command() {
	$items = array();

	$items['make-thank-you'] = array(
		'description' => 'Makes the thank you templates',
        'options' => array(
            'generator' => 'Generator to use when pulling down messages (specify multiple via comma). Default: ' . get_default_thank_you_generator(),
            'listgen' => 'List available generators.',
            'languages' => 'target languages (specify multiple by comma); if not specified will grab all available',
        )
	);

	return $items;
}

function drush_make_thank_you() {
    $generators = get_thank_you_generators();
    $default_gen = get_default_thank_you_generator();

    if (drush_get_option('listgen', false)) {
        drush_print(t('Available thank you generators'));
        foreach( $generators as $generator=>$class ) {
            drush_print("\t{$generator}");
        }
        return;
    }

    $genlist = explode(',', drush_get_option('generator', get_default_thank_you_generator()));
    $langs = drush_get_option('languages');
    $langs = $langs ? explode(',', $langs) : array();

    foreach( $genlist as $genname ) {
        $generator = new $generators[$genname]();
        $generator->execute($langs);
    }
}

/**
 * Returns 'friendly name' => 'class name' list of thank you generators.
 *
 * I thought about doing something drupal like and inspecting the registry
 * or globbing the FS; but that seemed evil.
 */
function get_thank_you_generators() {
    return array(
        '2012' => '\thank_you\generators\ThankYou2012',
        '20131202' => '\thank_you\generators\ThankYou20131202',
    );
}

function get_default_thank_you_generator() {
    $generators = array_keys(get_thank_you_generators());
    rsort($generators);
    return $generators[0];
}

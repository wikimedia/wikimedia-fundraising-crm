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
    drush_print('CiviCRM also has thank you templates for US donors - please check the template in modules/wmf_civicrm/templates/ is up to date. Search wmf_civicrm.install for _wmf_civicrm_update_offline_receipt() to see how to deploy an update to this template');
}

/**
 * Returns 'friendly name' => 'class name' list of thank you generators.
 *
 * I thought about doing something drupal like and inspecting the registry
 * or globbing the FS; but that seemed evil.
 */
function get_thank_you_generators() {
    return array(
        'generic' => '\thank_you\generators\ThankYou',
        'subject' => '\thank_you\generators\ThankYouSubject',
    );
}

function get_default_thank_you_generator() {
    // FIXME: yikes.
    $generators = array_keys(get_thank_you_generators());
    return $generators[0];
}

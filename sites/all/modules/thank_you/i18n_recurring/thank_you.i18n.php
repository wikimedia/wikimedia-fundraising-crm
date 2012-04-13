<?php

global $TYmsgs_recur;

# initialize the messages variable, if needed
if ( !isset( $TYmsgs_recur ) ){
    $TYmsgs_recur = array();
}

# whitelist of enabled language translations for emails
$languages_enabled = array(
//	'az' => 'thank_you.az.php',
//	'bg' => 'thank_you.bg.php',
//	'da' => 'thank_you.da.php',
//	'de' => 'thank_you.de.php',
//	'el' => 'thank_you.el.php',
	'en' => 'thank_you.en.php',
//	'es' => 'thank_you.es.php',
//	'es_ES' => 'thank_you.es_ES.php',
//	'fr' => 'thank_you.fr.php',
//	'gl' => 'thank_you.gl.php',
//	'id' => 'thank_you.id.php',
//	'it' => 'thank_you.it.php',
//	'ja' => 'thank_you.ja.php',
//    'nl' => 'thank_you.nl.php',
//    'pt' => 'thank_you.pt.php',
//    'ru' => 'thank_you.ru.php',
);

/*
 * Work through each of the enabled languages and include if
 * the i18n file exists
 */
foreach ( $languages_enabled as $lang => $file ) {
	if ( file_exists( dirname(__FILE__) . '/' . $file ) ) {
		require_once dirname(__FILE__) . '/' . $file;
	}
}
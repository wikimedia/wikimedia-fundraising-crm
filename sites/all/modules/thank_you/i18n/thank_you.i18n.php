<?php

global $TYmsgs;

# initialize the messages variable, if needed
if ( !isset( $TYmsgs ) ){
	$TYmsgs = array();
}

# whitelist of enabled language translations for emails
$languages_enabled = array(
//	'az' => 'thank_you.az.php',
//	'bg' => 'thank_you.bg.php',
	'da' => 'thank_you.da.php',
	'de' => 'thank_you.de.php',
	'en' => 'thank_you.en.php',
//	'es' => 'thank_you.es.php',
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
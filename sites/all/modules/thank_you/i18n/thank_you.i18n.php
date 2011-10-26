<?php

# initialize the messages variable, if needed
if ( !isset( $messages ) ){
	$messages = array();
}

# whitelist of enabled language translations for emails
$languages_enabled = array(
	'en' => 'thank_you.EN.php',
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
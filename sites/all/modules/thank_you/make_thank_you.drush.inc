<?php

function make_thank_you_drush_command() {
	$items = array();

	$items['make-thank-you'] = array(
		'description' => 'Makes the thank you templates',
	);

	return $items;
}


function drush_make_thank_you() {
	// Do this one first so we get old established email templates
	$ty2012 = new \thank_you\generators\ThankYou2012();
	$ty2012->execute();

	// And then have any new ones overwrite
	$ty2013 = new \thank_you\generators\ThankYou20131202();
	$ty2013->execute();
}

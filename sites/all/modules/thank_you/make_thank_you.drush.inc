<?php

function make_thank_you_drush_command() {
	$items = array();

	$items['make-thank-you'] = array(
		'description' => 'Makes the thank you templates',
	);

	return $items;
}


function drush_make_thank_you() {
	$ty2012 = new \thank_you\generators\ThankYou2012();
	$ty2012->execute();
}

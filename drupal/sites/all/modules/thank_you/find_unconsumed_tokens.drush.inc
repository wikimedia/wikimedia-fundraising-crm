<?php

use thank_you\generators\FindUnconsumedTokens;

function find_unconsumed_tokens_drush_command() {
	$items = array();

	$items['find-unconsumed-tokens'] = array(
		'description' => 'Comb the thank you templates for uneaten and misspelled tokens',
	);

	return $items;
}

function drush_find_unconsumed_tokens() {
    FindUnconsumedTokens::findAllTokens();
}

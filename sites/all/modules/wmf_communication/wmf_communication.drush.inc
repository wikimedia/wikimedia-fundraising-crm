<?php

function wmf_communication_drush_command()
{
    $items = array(
		'silverpop-import-mailings' => array(
			'description' => 'Import sent mailings from Silverpop',
			'options' => array(
				'days' => 'Number of days back to look for sent mailings'
			)
		)
    );

    return $items;
}

function drush_wmf_communication_silverpop_import_mailings()
{
	$set_days = variable_get('silverpop_mailing_days', 3);
	$days = drush_get_option('days', $set_days);
	module_invoke('wmf_communication', 'silverpop_import_mailings', $days);
}

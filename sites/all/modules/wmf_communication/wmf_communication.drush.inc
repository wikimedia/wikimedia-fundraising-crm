<?php

function wmf_communication_drush_command()
{
    $items = array(
        'wmf-send-letters' => array(
            'description' => 'Run a prepared mailing job',
            'arguments' => array(
                'job' => 'Job id',
            ),
            'required-arguments' => true,
        ),
		'silverpop-import-mailings' => array(
			'description' => 'Import sent mailings from Silverpop',
			'options' => array(
				'days' => 'Number of days back to look for sent mailings'
			)
		)
    );

    return $items;
}

function wmf_communication_drush_help($section)
{
    switch ($section) {
    case 'drush:wmf-send-letters':
        return dt('Run a prepared mailing job.');
    }
}

function drush_wmf_communication_wmf_send_letters()
{
    $options = drush_get_arguments();
    module_invoke('wmf_communication', 'send_letters', $options[1] );
}

function drush_wmf_communication_silverpop_import_mailings()
{
	$set_days = variable_get('silverpop_mailing_days', 3);
	$days = drush_get_option('days', $set_days);
	module_invoke('wmf_communication', 'silverpop_import_mailings', $days);
}

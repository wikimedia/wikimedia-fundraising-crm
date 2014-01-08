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

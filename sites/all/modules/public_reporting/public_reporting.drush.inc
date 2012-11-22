<?php

function public_reporting_drush_command()
{
    $items = array(
        'public-reporting-rebuild-aggregations' => array(
            'description' => 'Rebuild all aggregate data.',
        ),
        'public-reporting-update-aggregations' => array(
            'description' => 'Refresh aggregate data for the last 2 hours.',
        ),
    );

    return $items;
}

function public_reporting_drush_help( $section )
{
    switch ( $section ) {
    case 'drush:public-reporting-rebuild-aggregations':
        return dt('Rebuild all aggregate data.');
    case 'drush:public-reporting-update-aggregations':
        return dt('Refresh aggregate data for the last 2 hours.');
    }
}

function drush_public_reporting_rebuild_aggregations()
{
    $options = drush_get_merged_options();
    module_invoke('public_reporting', 'rebuild_aggregations', $options);
}

function drush_public_reporting_update_aggregations()
{
    $options = drush_get_merged_options();
    module_invoke('public_reporting', 'update_aggregations', $options);
}

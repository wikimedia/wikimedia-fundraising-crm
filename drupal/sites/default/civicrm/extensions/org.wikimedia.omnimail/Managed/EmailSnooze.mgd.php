<?php

return [
    [
        'name' => 'OptionValue_ActivityEmailSnoozed',
        'entity' => 'OptionValue',
        'cleanup' => 'unused',
        'update' => 'unmodified',
        'params' => [
            'version' => 4,
            'values' => [
                'option_group_id.name' => 'activity_type',
                'label' => 'Email Snoozed',
                'value' => 174,
                'name' => 'EmailSnoozed',
                'grouping' => NULL,
                'filter' => 1,
                'is_default' => FALSE,
                'description' => 'Email snoozed in Acoustic',
                'is_optgroup' => FALSE,
                'is_reserved' => TRUE,
                'is_active' => TRUE,
                'component_id' => NULL,
                'domain_id' => NULL,
                'visibility_id' => NULL,
                'icon' => 'fa-snooze',
                'color' => NULL,
            ],
            'match' => [
                'option_group_id',
                'name',
            ],
        ],
    ],
];

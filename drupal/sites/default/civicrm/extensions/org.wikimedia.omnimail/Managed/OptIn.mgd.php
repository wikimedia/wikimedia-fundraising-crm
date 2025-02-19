<?php

return [
  [
    'name' => 'OptionValue_ActivityOptIn',
    'entity' => 'OptionValue',
    'cleanup' => 'unused',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'option_group_id.name' => 'activity_type',
        'label' => 'OptIn',
        'value' => 195,
        'name' => 'OptIn',
        'grouping' => NULL,
        'filter' => 1,
        'weight' => 96,
        'is_default' => FALSE,
        'description' => 'Email optIn in Acoustic',
        'is_optgroup' => FALSE,
        'is_reserved' => TRUE,
        'is_active' => TRUE,
        'component_id' => NULL,
        'domain_id' => NULL,
        'visibility_id' => NULL,
        'icon' => 'fa-envelope-circle-check',
        'color' => NULL,
      ],
      'match' => [
        'option_group_id',
        'name',
      ],
    ],
  ],
];

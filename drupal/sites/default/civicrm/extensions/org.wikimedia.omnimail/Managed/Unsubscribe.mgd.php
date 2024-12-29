<?php

return [
  [
    'name' => 'unsubscribe',
    'entity' => 'OptionValue',
    'cleanup' => 'unused',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'option_group_id.name' => 'activity_type',
        'label' => 'Unsubscribe',
        'value' => 99,
        'name' => 'unsubscribe',
        'grouping' => NULL,
        'filter' => 1,
        'is_default' => FALSE,
        'description' => 'Unsubscribe requested in Acoustic',
        'is_optgroup' => FALSE,
        'is_reserved' => TRUE,
        'is_active' => TRUE,
        'component_id' => NULL,
        'domain_id' => NULL,
        'visibility_id' => NULL,
        'icon' => '',
        'color' => NULL,
      ],
      'match' => [
        'option_group_id',
        'name',
      ],
    ],
  ],
];

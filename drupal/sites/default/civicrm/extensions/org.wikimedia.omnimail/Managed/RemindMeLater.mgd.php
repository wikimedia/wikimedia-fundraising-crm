<?php

return [
  [
    'name' => 'OptionValue_ActivityRemindMeLater',
    'entity' => 'OptionValue',
    'cleanup' => 'unused',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'option_group_id.name' => 'activity_type',
        'label' => 'Remind me later',
        'value' => 191,
        'name' => 'remind_me_later',
        'grouping' => NULL,
        'filter' => 1,
        'is_default' => FALSE,
        'description' => 'Remind me later requested in Acoustic',
        'is_optgroup' => FALSE,
        'is_reserved' => TRUE,
        'is_active' => TRUE,
        'component_id' => NULL,
        'domain_id' => NULL,
        'visibility_id' => NULL,
        'icon' => 'fa-calendar-plus',
        'color' => NULL,
      ],
      'match' => [
        'option_group_id',
        'name',
      ],
    ],
  ],
];

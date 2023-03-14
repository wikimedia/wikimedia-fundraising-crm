<?php

use Civi\Api4\OptionValue;

return [
    [
      'name' => 'OptionValue_Thank_you_email',
      'entity' => 'OptionValue',
      'cleanup' => 'unused',
      'update' => 'unmodified',
      'params' => [
        'version' => 4,
        'values' => [
          'option_group_id.name' => 'activity_type',
          'label' => 'Thank you email',
          'value' => 100,
          'name' => 'Thank you email',
          'grouping' => NULL,
          'filter' => 1,
          'is_default' => FALSE,
          'weight' => 3,
          'description' => 'Automated thank you email',
          'is_optgroup' => FALSE,
          'is_reserved' => TRUE,
          'is_active' => TRUE,
          'component_id' => NULL,
          'domain_id' => NULL,
          'visibility_id' => NULL,
          'icon' => 'fa-envelope-open-o',
          'color' => NULL,
        ],
        'match' => [
          'option_group_id',
          'name',
        ],
      ],
    ],
  ];


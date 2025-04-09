<?php

return [
  [
    'name' => 'OptionValue_ContributionSoft',
    'entity' => 'OptionValue',
    'cleanup' => 'unused',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'option_group_id.name' => 'soft_credit_type',
        'label' => 'Banking Institution',
        'value' => 13,
        'name' => 'Banking Institution',
        'grouping' => NULL,
        'filter' => 1,
        'is_default' => FALSE,
        'weight' => 3,
        'description' => 'Institution facilitating donor advised fund',
        'is_optgroup' => FALSE,
        'is_reserved' => TRUE,
        'is_active' => TRUE,
        'component_id' => NULL,
        'domain_id' => NULL,
        'visibility_id' => NULL,
        'icon' => 'fa-building-columns',
        'color' => NULL,
      ],
      'match' => [
        'option_group_id',
        'name',
      ],
    ],
  ],
];

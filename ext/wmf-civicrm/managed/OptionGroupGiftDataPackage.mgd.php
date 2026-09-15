<?php

return [
  [
    'name' => 'OptionGroup_Gift_Data_Package',
    'entity' => 'OptionGroup',
    'cleanup' => 'unused',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'name' => 'Gift_Data_Package',
        'title' => 'Gift Data :: Package',
        'is_reserved' => FALSE,
      ],
      'match' => [
        'name',
      ],
    ],
  ],
  [
    'name' => 'OptionGroup_Gift_Data_Package_OptionValue_OCT1B3ES',
    'entity' => 'OptionValue',
    'cleanup' => 'unused',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'option_group_id.name' => 'Gift_Data_Package',
        'label' => 'OCT1B3ES',
        'value' => 'OCT1B3ES',
        'name' => 'OCT1B3ES',
      ],
      'match' => [
        'option_group_id',
        'name',
        'value',
      ],
    ],
  ],
  [
    'name' => 'OptionGroup_Gift_Data_Package_OptionValue_OCT1B2ES',
    'entity' => 'OptionValue',
    'cleanup' => 'unused',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'option_group_id.name' => 'Gift_Data_Package',
        'label' => 'OCT1B2ES',
        'value' => 'OCT1B2ES',
        'name' => 'OCT1B2ES',
      ],
      'match' => [
        'option_group_id',
        'name',
        'value',
      ],
    ],
  ],
];

<?php

return [
  [
    'name' => 'OptionGroup_stage_20080616181942',
    'entity' => 'OptionGroup',
    'cleanup' => 'unused',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'name' => 'stage_20080616181942',
        'title' => 'Stage',
        'is_reserved' => FALSE,
        'option_value_fields' => ['name', 'label', 'description'],
      ],
      'match' => [
        'name',
      ],
    ],
  ],
  [
    'name' => 'OptionGroup_stage_20080616181942_OptionValue_Qualification',
    'entity' => 'OptionValue',
    'cleanup' => 'unused',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'option_group_id.name' => 'stage_20080616181942',
        'label' => 'Qualification',
        'value' => 'Qualification',
        'name' => 'Qualification',
      ],
      'match' => [
        'option_group_id',
        'name',
        'value',
      ],
    ],
  ],
  [
    'name' => 'OptionGroup_stage_20080616181942_OptionValue_declined',
    'entity' => 'OptionValue',
    'cleanup' => 'unused',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'option_group_id.name' => 'stage_20080616181942',
        'label' => 'Disqualified',
        'value' => 'Disqualified',
        'name' => 'declined',
      ],
      'match' => [
        'option_group_id',
        'name',
        'value',
      ],
    ],
  ],
  [
    'name' => 'OptionGroup_stage_20080616181942_OptionValue_Cultivation',
    'entity' => 'OptionValue',
    'cleanup' => 'unused',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'option_group_id.name' => 'stage_20080616181942',
        'label' => 'Cultivation',
        'value' => 'Cultivation',
        'name' => 'Cultivation',
      ],
      'match' => [
        'option_group_id',
        'name',
        'value',
      ],
    ],
  ],
  [
    'name' => 'OptionGroup_stage_20080616181942_OptionValue_planning',
    'entity' => 'OptionValue',
    'cleanup' => 'unused',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'option_group_id.name' => 'stage_20080616181942',
        'label' => 'Planning',
        'value' => 'planning',
        'name' => 'planning',
        'is_active' => FALSE,
      ],
      'match' => [
        'option_group_id',
        'name',
        'value',
      ],
    ],
  ],
  [
    'name' => 'OptionGroup_stage_20080616181942_OptionValue_solicitation',
    'entity' => 'OptionValue',
    'cleanup' => 'unused',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'option_group_id.name' => 'stage_20080616181942',
        'label' => 'Solicitation',
        'value' => 'solicitation',
        'name' => 'solicitation',
      ],
      'match' => [
        'option_group_id',
        'name',
        'value',
      ],
    ],
  ],
  [
    'name' => 'OptionGroup_stage_20080616181942_OptionValue_research',
    'entity' => 'OptionValue',
    'cleanup' => 'unused',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'option_group_id.name' => 'stage_20080616181942',
        'label' => 'Research',
        'value' => 'research',
        'name' => 'research',
        'is_active' => FALSE,
      ],
      'match' => [
        'option_group_id',
        'name',
        'value',
      ],
    ],
  ],
  [
    'name' => 'OptionGroup_stage_20080616181942_OptionValue_stewardship',
    'entity' => 'OptionValue',
    'cleanup' => 'unused',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'option_group_id.name' => 'stage_20080616181942',
        'label' => 'Stewardship',
        'value' => 'stewardship',
        'name' => 'stewardship',
      ],
      'match' => [
        'option_group_id',
        'name',
        'value',
      ],
    ],
  ],
  [
    'name' => 'OptionGroup_stage_20080616181942_OptionValue_ready',
    'entity' => 'OptionValue',
    'cleanup' => 'unused',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'option_group_id.name' => 'stage_20080616181942',
        'label' => 'See primary contact',
        'value' => 'see primary',
        'name' => 'ready',
      ],
      'match' => [
        'option_group_id',
        'name',
        'value',
      ],
    ],
  ],
  [
    'name' => 'OptionGroup_stage_20080616181942_OptionValue_identification',
    'entity' => 'OptionValue',
    'cleanup' => 'unused',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'option_group_id.name' => 'stage_20080616181942',
        'label' => 'Identification',
        'value' => 'identification',
        'name' => 'identification',
      ],
      'match' => [
        'option_group_id',
        'name',
        'value',
      ],
    ],
  ],
  [
    'name' => 'CustomGroup_MG_Stage',
    'entity' => 'CustomGroup',
    'cleanup' => 'unused',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'name' => 'MG_Stage',
        'title' => 'MG Stage',
        'extends' => 'Activity',
        'weight' => 56,
        'created_date' => '2026-05-29 18:58:26',
      ],
      'match' => ['name'],
    ],
  ],
  [
    'name' => 'CustomGroup_MG_Stage_CustomField_Changed_to',
    'entity' => 'CustomField',
    'cleanup' => 'unused',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'custom_group_id.name' => 'MG_Stage',
        'name' => 'Changed_to',
        'label' => 'Changed to',
        'html_type' => 'Select',
        'is_view' => TRUE,
        'text_length' => 255,
        'column_name' => 'changed_to_278',
        'option_group_id.name' => 'stage_20080616181942',
      ],
      'match' => [
        'name',
        'custom_group_id',
      ],
    ],
  ],
];

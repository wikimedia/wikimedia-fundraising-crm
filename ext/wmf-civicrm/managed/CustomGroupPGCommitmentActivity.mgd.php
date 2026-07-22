<?php
return [
  [
    'name' => 'CustomGroup_PG_Commitment_Activity',
    'entity' => 'CustomGroup',
    'cleanup' => 'unused',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'name' => 'PG_Commitment_Activity',
        'title' => 'PG - Commitment Activity',
        'table_name' => 'civicrm_value_pg_commitment_25',
        'extends' => 'Activity',
        'extends_entity_column_value' => [
          '146',
        ],
        'weight' => 34,
        'collapse_adv_display' => TRUE,
      ],
      'match' => [
        'name',
      ],
    ],
  ],
  [
    'name' => 'CustomGroup_PG_Commitment_Activity_CustomField_Commitment_Confirmation_Date',
    'entity' => 'CustomField',
    'cleanup' => 'unused',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'custom_group_id.name' => 'PG_Commitment_Activity',
        'name' => 'Commitment_Confirmation_Date',
        'label' => 'Commitment Confirmation Date',
        'column_name' => 'commitment_confirmation_date_299',
        'data_type' => 'Date',
        'html_type' => 'Select Date',
        'is_searchable' => TRUE,
        'is_search_range' => TRUE,
        'help_pre' => 'Only add a confirmation data when the WLS form is completed, or we receive additional documentation such as our Endowment Legacy Gift Confirmation Form.',
        'text_length' => 255,
        'date_format' => 'mm/dd/yy',
        'note_columns' => 60,
        'note_rows' => 4,
      ],
      'match' => [
        'name',
        'custom_group_id',
      ],
    ],
  ],
  [
    'name' => 'CustomGroup_PG_Commitment_Activity_CustomField_Commitment_Confirmed_',
    'entity' => 'CustomField',
    'cleanup' => 'unused',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'custom_group_id.name' => 'PG_Commitment_Activity',
        'name' => 'Commitment_Confirmed_',
        'label' => 'Commitment Confirmed?',
        'column_name' => 'commitment_confirmed__298',
        'data_type' => 'Boolean',
        'html_type' => 'Radio',
        'is_required' => TRUE,
        'is_searchable' => TRUE,
        'text_length' => 255,
        'note_columns' => 60,
        'note_rows' => 4,
      ],
      'match' => [
        'name',
        'custom_group_id',
      ],
    ],
  ],
];

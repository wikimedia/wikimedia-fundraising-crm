<?php
use CRM_Wmf_ExtensionUtil as E;

return [
  [
    'name' => 'CustomGroup_Appeal_Change_Reason',
    'entity' => 'CustomGroup',
    'cleanup' => 'unused',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'name' => 'Appeal_Change_Reason',
        'title' => E::ts('Appeal Change Reason'),
        'table_name' => 'civicrm_value_appeal_change_reason',
        'extends' => 'Contribution',
        'collapse_display' => TRUE,
        'weight' => 60,
        'collapse_adv_display' => TRUE,
      ],
      'match' => ['name'],
    ],
  ],
  [
    'name' => 'OptionGroup_Appeal_Change_Reason',
    'entity' => 'OptionGroup',
    'cleanup' => 'unused',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'name' => 'Appeal_Change_Reason',
        'title' => E::ts('Appeal Change Reason'),
        'data_type' => 'String',
        'is_reserved' => FALSE,
        'option_value_fields' => ['name', 'label'],
      ],
      'match' => ['name'],
    ],
  ],
  [
    'name' => 'OptionGroup_Appeal_Change_Reason_OptionValue_MG_Engagement',
    'entity' => 'OptionValue',
    'cleanup' => 'unused',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'option_group_id.name' => 'Appeal_Change_Reason',
        'label' => E::ts('MG Engagement'),
        'value' => 'MG Engagement',
        'name' => 'MG_Engagement',
      ],
      'match' => [
        'option_group_id',
        'name',
        'value',
      ],
    ],
  ],
  [
    'name' => 'OptionGroup_Appeal_Change_Reason_OptionValue_Direct_Mail',
    'entity' => 'OptionValue',
    'cleanup' => 'unused',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'option_group_id.name' => 'Appeal_Change_Reason',
        'label' => E::ts('Direct Mail'),
        'value' => 'Direct Mail',
        'name' => 'Direct_Mail',
      ],
      'match' => [
        'option_group_id',
        'name',
        'value',
      ],
    ],
  ],
  [
    'name' => 'OptionGroup_Appeal_Change_Reason_OptionValue_DAF_Email',
    'entity' => 'OptionValue',
    'cleanup' => 'unused',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'option_group_id.name' => 'Appeal_Change_Reason',
        'label' => E::ts('DAF Email'),
        'value' => 'DAF Email',
        'name' => 'DAF_Email',
      ],
      'match' => [
        'option_group_id',
        'name',
        'value',
      ],
    ],
  ],
  [
    'name' => 'OptionGroup_Appeal_Change_Reason_OptionValue_Relationship_Manager',
    'entity' => 'OptionValue',
    'cleanup' => 'unused',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'option_group_id.name' => 'Appeal_Change_Reason',
        'label' => E::ts('Relationship Manager'),
        'value' => 'Relationship Manager',
        'name' => 'Relationship_Manager',
      ],
      'match' => [
        'option_group_id',
        'name',
        'value',
      ],
    ],
  ],
  [
    'name' => 'CustomGroup_Appeal_Change_Reason_CustomField_Change_Reason',
    'entity' => 'CustomField',
    'cleanup' => 'unused',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'custom_group_id.name' => 'Appeal_Change_Reason',
        'name' => 'Change_Reason',
        'label' => E::ts('Change Reason'),
        'column_name' => 'change_reason',
        'html_type' => 'Select',
        'is_view' => TRUE,
        'text_length' => 255,
        'option_group_id.name' => 'Appeal_Change_Reason',
      ],
      'match' => [
        'name',
        'custom_group_id',
      ],
    ],
  ],
  [
    'name' => 'CustomGroup_Appeal_Change_Reason_CustomField_Entity_Table',
    'entity' => 'CustomField',
    'cleanup' => 'unused',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'custom_group_id.name' => 'Appeal_Change_Reason',
        'name' => 'Entity_Table',
        'label' => E::ts('Entity Table'),
        'column_name' => 'reason_entity_table',
        'html_type' => 'Text',
        'is_view' => TRUE,
        'text_length' => 255,
      ],
      'match' => [
        'name',
        'custom_group_id',
      ],
    ],
  ],
  [
    'name' => 'CustomGroup_Appeal_Change_Reason_CustomField_Entity_ID',
    'entity' => 'CustomField',
    'cleanup' => 'unused',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'custom_group_id.name' => 'Appeal_Change_Reason',
        'name' => 'Entity_ID',
        'label' => E::ts('Entity ID'),
        'column_name' => 'reason_entity_id',
        'data_type' => 'Int',
        'html_type' => 'Text',
        'is_view' => TRUE,
      ],
      'match' => [
        'name',
        'custom_group_id',
      ],
    ],
  ],
];

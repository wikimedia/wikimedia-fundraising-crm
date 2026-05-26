<?php
use CRM_Wmf_ExtensionUtil as E;

return [
  [
    'name' => 'CustomGroup_Email',
    'entity' => 'CustomGroup',
    'cleanup' => 'unused',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'name' => 'Email',
        'title' => E::ts('Email'),
        'table_name' => 'civicrm_value_email_activity',
        'extends' => 'Activity',
        'extends_entity_column_value' => \CRM_Core_PseudoConstant::getKey('CRM_Activity_BAO_Activity', 'activity_type_id', 'Email'),
        'collapse_adv_display' => TRUE,
      ],
      'match' => [
        'name',
      ],
    ],
  ],
  [
    'name' => 'CustomGroup_Email_Workflow',
    'entity' => 'CustomField',
    'cleanup' => 'unused',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'custom_group_id.name' => 'Email',
        'name' => 'Workflow',
        'label' => E::ts('Workflow'),
        'html_type' => 'Text',
        'is_view' => TRUE,
        'text_length' => 255,
        'column_name' => 'workflow',
      ],
      'match' => [
        'name',
        'custom_group_id',
      ],
    ],
  ],
];

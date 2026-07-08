<?php

use CRM_Wmf_ExtensionUtil as E;
use Civi\WMFHook\CalculatedData;

return [
  'name' => 'WMFDonorHistory',
  'class' => 'CRM_Wmf_DAO_WMFDonorHistory',
  'table' => 'wmf_donor_history',
  'getInfo' => fn() => [
    'title' => E::ts('WMF Donor History'),
    'title_plural' => E::ts('WMF Donor History'),
    'description' => E::ts('History of changes to wmf_donor segment and status fields.'),
    'log' => FALSE,
  ],
  'getFields' => function () {
    $fields = [
      'entity_id' => [
        'name' => 'entity_id',
        'sql_type' => 'int unsigned',
        'input_type' => 'EntityRef',
        'title' => E::ts('Contact ID'),
        'required' => TRUE,
        'entity_reference' => [
          'entity' => 'Contact',
          'key' => 'id',
          'on_delete' => 'CASCADE',
        ],
      ],
    ];
    foreach ((new CalculatedData())->getLoggedFields() as $fieldName => $field) {
      $fields[$fieldName] = [
        'name' => $fieldName,
        'sql_type' => strtolower($field['data_type']),
        'input_type' => $field['html_type'],
        'title' => $field['label'],
        'pseudoconstant' => [
          'callback' => ['Civi\WMFHook\CalculatedData', 'getHistoryFieldOptions'],
        ],
      ];
    }
    $fields['changed_fields'] = [
      'name' => 'changed_fields',
      'sql_type' => 'varchar(255)',
      'input_type' => 'Select',
      'title' => E::ts('Changed Fields'),
      'description' => E::ts('Fields changed in this row.'),
      'required' => TRUE,
      'serialize' => \CRM_Core_DAO::SERIALIZE_SEPARATOR_BOOKEND,
      'pseudoconstant' => [
        'option_group_name' => 'wmf_donor_history_changed_field',
      ],
    ];
    $fields['log_date'] = [
      'name' => 'log_date',
      'sql_type' => 'timestamp',
      'input_type' => 'Date',
      'title' => E::ts('Log Date'),
      'required' => TRUE,
      'default' => 'CURRENT_TIMESTAMP',
    ];
    $fields['log_id'] = [
      'name' => 'log_id',
      'sql_type' => 'bigint unsigned',
      'input_type' => 'Number',
      'title' => E::ts('Log ID'),
      'required' => TRUE,
      'primary_key' => TRUE,
      'auto_increment' => TRUE,
    ];
    return $fields;
  },
  'getIndices' => fn() => [
    'index_entity_id' => [
      'name' => 'index_entity_id',
      'fields' => ['entity_id' => TRUE],
    ],
  ],
  'getPaths' => fn() => [],
];

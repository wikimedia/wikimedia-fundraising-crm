<?php
use CRM_Monolog_ExtensionUtil as E;

return [
  'name' => 'Monolog',
  'table' => 'civicrm_monolog',
  'class' => 'CRM_Monolog_DAO_Monolog',
  'getInfo' => fn() => [
    'title' => E::ts('Monolog'),
    'title_plural' => E::ts('Monologs'),
    'description' => E::ts('Monolog log configuration'),
    'log' => FALSE,
  ],
  'getIndices' => fn() => [
    'UI_name' => [
      'fields' => [
        'name' => TRUE,
      ],
      'unique' => TRUE,
      'add' => '1.0',
    ],
  ],
  'getFields' => fn() => [
    'id' => [
      'title' => E::ts('ID'),
      'sql_type' => 'int unsigned',
      'input_type' => 'Number',
      'required' => TRUE,
      'description' => E::ts('Unique Monolog ID'),
      'primary_key' => TRUE,
      'auto_increment' => TRUE,
    ],
    'name' => [
      'title' => E::ts('Unique name'),
      'sql_type' => 'varchar(32)',
      'input_type' => 'Text',
      'input_attrs' => [
        'size' => '32',
      ],
    ],
    'channel' => [
      'title' => E::ts('Log service channel'),
      'sql_type' => 'varchar(16)',
      'input_type' => 'Text',
      'input_attrs' => [
        'size' => '16',
      ],
    ],
    'description' => [
      'title' => E::ts('Description'),
      'sql_type' => 'varchar(255)',
      'input_type' => 'TextArea',
    ],
    'type' => [
      'title' => E::ts('Type of log service'),
      'sql_type' => 'varchar(16)',
      'input_type' => 'Select',
      'add' => '1.0',
      'pseudoconstant' => [
        'callback' => 'CRM_Monolog_BAO_Monolog::getTypes',
      ],
    ],
    'minimum_severity' => [
      'title' => E::ts('Minimum Severity'),
      'sql_type' => 'varchar(16)',
      'input_type' => 'Select',
      'pseudoconstant' => [
        'callback' => 'CRM_Monolog_BAO_Monolog::getSeverities',
      ],
    ],
    'weight' => [
      'title' => E::ts('Weight'),
      'sql_type' => 'int',
      'input_type' => 'Text',
    ],
    'is_active' => [
      'title' => E::ts('Is the handler active'),
      'sql_type' => 'boolean',
      'input_type' => 'CheckBox',
    ],
    'is_final' => [
      'title' => E::ts('Is this the final handler to apply'),
      'sql_type' => 'boolean',
      'input_type' => 'CheckBox',
    ],
    'is_default' => [
      'title' => E::ts('Is default log service'),
      'sql_type' => 'boolean',
      'input_type' => 'CheckBox',
    ],
    'configuration_options' => [
      'title' => E::ts('Configuration options'),
      'sql_type' => 'text',
      'input_type' => 'TextArea',
      'serialize' => constant('CRM_Core_DAO::SERIALIZE_JSON'),
    ],
  ],
];

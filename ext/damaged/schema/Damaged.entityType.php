<?php
use CRM_Damaged_ExtensionUtil as E;
return [
  'name' => 'Damaged',
  'table' => 'damaged_view',
  'class' => 'CRM_Damaged_DAO_Damaged',
  'getInfo' => fn() => [
    'title' => E::ts('Damaged'),
    'title_plural' => E::ts('Damageds'),
    'description' => E::ts('Smashpig damaged table'),
    'log' => TRUE,
  ],
  'getPaths' => fn() => [
    'update' => 'civicrm/damaged/edit?action=update&id=[id]&reset=1',
    'delete' => 'civicrm/damaged/edit?action=delete&id=[id]&reset=1',
  ],
  'getIndices' => fn() => [
    'idx_damaged_original_date' => [
      'fields' => [
        'original_date' => TRUE,
      ],
      'unique' => TRUE,
    ],
    'idx_damaged_original_date_original_queue' => [
      'fields' => [
        'original_date' => TRUE,
        'original_queue' => TRUE,
      ],
      'unique' => TRUE,
    ],
    'idx_damaged_retry_date' => [
      'fields' => [
        'retry_date' => TRUE,
      ],
      'unique' => TRUE,
    ],
    'idx_damaged_order_id_gateway' => [
      'fields' => [
        'order_id' => TRUE,
        'gateway' => TRUE,
      ],
      'unique' => TRUE,
    ],
    'idx_damaged_gateway_txn_id_gateway' => [
      'fields' => [
        'gateway_txn_id' => TRUE,
        'gateway' => TRUE,
      ],
      'unique' => TRUE,
    ],
  ],
  'getFields' => fn() => [
    'id' => [
      'title' => E::ts('ID'),
      'sql_type' => 'int unsigned',
      'input_type' => 'Number',
      'required' => TRUE,
      'description' => E::ts('Unique Damaged Table Row ID'),
      'primary_key' => TRUE,
      'auto_increment' => TRUE,
    ],
    'original_date' => [
      'title' => E::ts('Original Date'),
      'sql_type' => 'datetime',
      'input_type' => 'Datetime',
      'required' => TRUE,
      'description' => E::ts('Original date'),
    ],
    'damaged_date' => [
      'title' => E::ts('Damaged Date'),
      'sql_type' => 'datetime',
      'input_type' => 'Datetime',
      'required' => TRUE,
      'description' => E::ts('Damage date'),
    ],
    'retry_date' => [
      'title' => E::ts('Retry Date'),
      'sql_type' => 'datetime',
      'input_type' => 'Datetime',
      'description' => E::ts('Retry date'),
    ],
    'original_queue' => [
      'title' => E::ts('Original Queue'),
      'sql_type' => 'varchar(0)',
      'input_type' => 'Character',
      'required' => TRUE,
      'description' => E::ts('Original Queue'),
    ],
    'gateway' => [
      'title' => E::ts('Gateway'),
      'sql_type' => 'varchar(0)',
      'input_type' => 'Character',
      'description' => E::ts('Gateway'),
    ],
    'order_id' => [
      'title' => E::ts('Order ID'),
      'sql_type' => 'varchar(0)',
      'input_type' => 'Character',
      'description' => E::ts('Order ID'),
    ],
    'gateway_txn_id' => [
      'title' => E::ts('Gateway Txn ID'),
      'sql_type' => 'varchar(0)',
      'input_type' => 'Character',
      'description' => E::ts('Gateway Transaction ID'),
    ],
    'error' => [
      'title' => E::ts('Error'),
      'sql_type' => 'text',
      'input_type' => 'Text',
      'description' => E::ts('Error'),
    ],
    'trace' => [
      'title' => E::ts('Trace'),
      'sql_type' => 'text',
      'input_type' => 'Text',
      'description' => E::ts('Error'),
    ],
    'message' => [
      'title' => E::ts('Message'),
      'sql_type' => 'text',
      'input_type' => 'Text',
      'required' => TRUE,
      'description' => E::ts('Error'),
    ],
  ],
];

<?php

use CRM_Wmf_ExtensionUtil as E;

return [
  'name' => 'GrantTransaction',
  'class' => 'CRM_Wmf_DAO_GrantTransaction',
  'table' => 'civicrm_grant_transaction',
  'getInfo' => fn() => [
    'title' => E::ts('Gateway Grant Transaction'),
    'description' => E::ts('Stores gateway grant transaction and settlement data.'),
  ],

  'getFields' => fn() => [

    // ---- Primary key ----
    'id' => [
      'name' => 'id',
      'sql_type' => 'int unsigned',
      'required' => TRUE,
      'primary_key' => TRUE,
      'auto_increment' => TRUE,
      'input_type' => 'Number',
      'title' => E::ts('ID'),
    ],

    // ---- Identifiers ----
    'grant_provider' => [
      'name' => 'grant_provider',
      'sql_type' => 'varchar(128)',
      'input_type' => 'Text',
      'title' => E::ts('Grant Provider'),
    ],
    'order_id' => [
      'name' => 'order_id',
      'sql_type' => 'varchar(128)',
      'input_type' => 'Text',
      'title' => E::ts('Order ID'),
    ],
    'gateway' => [
      'name' => 'gateway',
      'sql_type' => 'varchar(128)',
      'input_type' => 'Text',
      'title' => E::ts('Gateway'),
    ],
    'gateway_txn_id' => [
      'name' => 'gateway_txn_id',
      'sql_type' => 'varchar(128)',
      'input_type' => 'Text',
      'title' => E::ts('Gateway Transaction ID'),
      'unique_name' => 'gateway_txn_id',
    ],
    'audit_file_gateway' => [
      'name' => 'audit_file_gateway',
      'sql_type' => 'varchar(128)',
      'input_type' => 'Text',
      'title' => E::ts('Audit File Gateway Reference'),
    ],

    // ---- Dates (MySQL TIMESTAMP) ----
    'date' => [
      'name' => 'date',
      'sql_type' => 'timestamp',
      'input_type' => NULL,
      'title' => E::ts('Transaction Date'),
    ],
    'settled_date' => [
      'name' => 'settled_date',
      'sql_type' => 'timestamp',
      'input_type' => NULL,
      'title' => E::ts('Settled Date'),
    ],

    // ---- Backend processor ----
    'backend_processor' => [
      'name' => 'backend_processor',
      'sql_type' => 'varchar(128)',
      'input_type' => 'Text',
      'title' => E::ts('Backend Processor'),
    ],
    'backend_txn_id' => [
      'name' => 'backend_txn_id',
      'sql_type' => 'varchar(128)',
      'input_type' => 'Text',
      'title' => E::ts('Backend Processor Transaction ID'),
    ],

    // ---- Settlement ----
    'settlement_batch_reference' => [
      'name' => 'settlement_batch_reference',
      'sql_type' => 'varchar(128)',
      'input_type' => 'Text',
      'title' => E::ts('Settlement Batch Reference'),
    ],
    'settled_currency' => [
      'name' => 'settled_currency',
      'sql_type' => 'varchar(3)',
      'input_type' => 'Text',
      'title' => E::ts('Settled Currency'),
    ],
    'settled_total_amount' => [
      'name' => 'settled_total_amount',
      'sql_type' => 'decimal(18,9)',
      'title' => E::ts('Settled Total Amount'),
    ],
    'settled_net_amount' => [
      'name' => 'settled_net_amount',
      'sql_type' => 'decimal(18,9)',
      'title' => E::ts('Settled Net Amount'),
    ],
    'settled_fee_amount' => [
      'name' => 'settled_fee_amount',
      'sql_type' => 'decimal(18,9)',
      'title' => E::ts('Settled Fee Amount'),
    ],

    // ---- Original amounts ----
    'original_currency' => [
      'name' => 'original_currency',
      'sql_type' => 'varchar(3)',
      'input_type' => 'Text',
      'title' => E::ts('Original Currency'),
    ],
    'original_total_amount' => [
      'name' => 'original_total_amount',
      'sql_type' => 'decimal(18,9)',
      'title' => E::ts('Original Total Amount'),
    ],
    'original_fee_amount' => [
      'name' => 'original_fee_amount',
      'sql_type' => 'decimal(18,9)',
      'title' => E::ts('Original Fee Amount'),
    ],

    // ---- Payment ----
    'payment_method' => [
      'name' => 'payment_method',
      'sql_type' => 'varchar(128)',
      'input_type' => 'Text',
      'title' => E::ts('Payment Method'),
    ],
  ],
  'contribution_id' => [
    'name' => 'contribution_id',
    'sql_type' => 'int unsigned',
    'input_type' => NULL,
    'title' => E::ts('Contribution ID'),
    'description' => E::ts('Optional link to a CiviCRM Contribution record.'),
    'entity_reference' => [
      'entity' => 'Contribution',
      'key' => 'id',
    ],
  ],

  'getIndices' => fn() => [
    'index_gateway_txn_id_gateway' => [
      'name' => 'index_gateway_txn_id_gateway',
      'fields' => [
        'gateway_txn_id' => TRUE,
        'gateway' => TRUE,
      ],
      'unique' => TRUE,
    ],
  ],
];

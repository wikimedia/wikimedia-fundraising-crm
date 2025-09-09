<?php

use CRM_Wmf_ExtensionUtil as E;

return [
  'name'  => 'TransactionLog',
  'class' => 'CRM_Wmf_DAO_TransactionLog',
  'table' => 'civicrm_transaction_log',
  'getInfo' => fn() => [
    'title'       => E::ts('Transaction Log'),
    'description' => E::ts('Gets payment gateway transaction logs (SmashPig).'),
    'add'         => '1.0',
  ],

  'getFields' => fn() => [
    'id' => [
      'name'           => 'id',
      'type'           => CRM_Utils_Type::T_INT,
      'sql_type'       => 'bigint unsigned',
      'input_type'     => 'Number',
      'title'          => E::ts('ID'),
      'required'       => TRUE,
      'primary_key'    => TRUE,
      'auto_increment' => TRUE,
    ],
    'date' => [
      'name'       => 'date',
      'type'       => CRM_Utils_Type::T_DATE + CRM_Utils_Type::T_TIME,
      'sql_type'   => 'datetime',
      'input_type' => 'Date',
      'title'      => E::ts('Date'),
      'required'   => TRUE,
    ],
    'gateway' => [
      'name'       => 'gateway',
      'type'       => CRM_Utils_Type::T_STRING,
      'sql_type'   => 'varchar(255)',
      'input_type' => 'Text',
      'title'      => E::ts('Gateway'),
      'required'   => TRUE,
    ],
    'gateway_account' => [
      'name'       => 'gateway_account',
      'type'       => CRM_Utils_Type::T_STRING,
      'sql_type'   => 'varchar(255)',
      'input_type' => 'Text',
      'title'      => E::ts('Gateway Account'),
    ],
    'order_id' => [
      'name'       => 'order_id',
      'type'       => CRM_Utils_Type::T_STRING,
      'sql_type'   => 'varchar(255)',
      'input_type' => 'Text',
      'title'      => E::ts('Order ID'),
    ],
    'gateway_txn_id' => [
      'name'       => 'gateway_txn_id',
      'type'       => CRM_Utils_Type::T_STRING,
      'sql_type'   => 'varchar(128)',
      'input_type' => 'Text',
      'title'      => E::ts('Gateway Transaction ID'),
    ],
    'message' => [
      'name'       => 'message',
      'sql_type'   => 'text',
      'input_type' => 'TextArea',
      'title'      => E::ts('Message'),
      'required'   => TRUE,
    ],
    'payment_method' => [
      'name'       => 'payment_method',
      'sql_type'   => 'varchar(16)',
      'input_type' => 'Text',
      'title'      => E::ts('Payment Method'),
    ],
    'is_resolved' => [
      'name'       => 'is_resolved',
      'sql_type'   => 'tinyint(1)',
      'input_type' => 'CheckBox',
      'title'      => E::ts('Is Resolved'),
      'required'   => TRUE,
      'default'    => 0,
    ],
  ],
];

<?php

use CRM_Wmf_ExtensionUtil as E;

return [
  'name' => 'PaymentAttemptLabel',
  'class' => 'CRM_Wmf_DAO_PaymentAttemptLabel',
  'table' => 'civicrm_payment_attempt_label',
  'getInfo' => fn() => [
    'title' => E::ts('Payment Attempt Label'),
    'title_plural' => E::ts('Payment Attempt Labels'),
    'description' => E::ts('Labels for individual payment attempts from payments-wiki.'),
  ],

  'getFields' => fn() => [

    // ---- Primary key ----
    'id' => [
      'sql_type' => 'int unsigned',
      'required' => TRUE,
      'primary_key' => TRUE,
      'auto_increment' => TRUE,
      'input_type' => 'Number',
      'title' => E::ts('ID'),
    ],
    'order_id' => [
      'sql_type' => 'varchar(64)',
      'input_type' => 'Text',
      'title' => E::ts('Order ID (Invoice ID)'),
      'entity_reference' => [
        'entity' => 'PaymentAttempt',
        'key' => 'order_id',
        'fk' => FALSE,
      ],
    ],
    'is_fraud' => [
      'sql_type' => 'boolean',
      'input_type' => 'CheckBox',
      'title' => E::ts('Is Fraud'),
    ],
  ],

  'getIndices' => fn() => [
    'index_order_id' => [
      'name' => 'index_order_id',
      'fields' => [
        'order_id' => TRUE,
      ],
      'unique' => TRUE,
    ],
  ],
];

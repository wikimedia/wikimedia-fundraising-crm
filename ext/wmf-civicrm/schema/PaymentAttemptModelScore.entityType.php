<?php
use CRM_Wmf_ExtensionUtil as E;

return [
  'name' => 'PaymentAttemptModelScore',
  'table' => 'civicrm_payment_attempt_model_score',
  'class' => 'CRM_Wmf_DAO_PaymentAttemptModelScore',
  'getInfo' => fn() => [
    'title' => E::ts('Payment Attempt Model Score'),
    'title_plural' => E::ts('Payment Attempt Model Scores'),
    'description' => E::ts('Scores calculated by fraud models for payment attempts.'),
    'log' => TRUE,
  ],
  'getFields' => fn() => [
    'id' => [
      'title' => E::ts('ID'),
      'sql_type' => 'int unsigned',
      'input_type' => 'Number',
      'required' => TRUE,
      'description' => E::ts('Unique PaymentAttemptModelScore ID'),
      'primary_key' => TRUE,
      'auto_increment' => TRUE,
    ],
    'order_id' => [
      'sql_type' => 'varchar(64)',
      'input_type' => 'Text',
      'title' => E::ts('Order ID'),
      'entity_reference' => [
        'entity' => 'PaymentAttempt',
        'key' => 'order_id',
        'fk' => FALSE,
      ],
    ],
    'score' => [
      'sql_type' => 'decimal(5,4)',
      'input_type' => 'Decimal',
      'title' => E::ts('Score'),
    ],
    'model_role' => [
      'sql_type' => 'varchar(32)',
      'input_type' => 'Text',
      'title' => E::ts('Model role'),
      'description' => E::ts('Role served by the model at the time the score was applied (production, alpha, beta)'),
    ],
    'model_version' => [
      'sql_type' => 'varchar(32)',
      'input_type' => 'Text',
      'title' => E::ts('Model version'),
    ],
  ],
  'getIndices' => fn() => [
    'index_order_id_model_version' => [
      'name' => 'index_order_id_model_version',
      'fields' => [
        'order_id' => TRUE,
        'model_version' => TRUE,
      ],
      'unique' => TRUE,
    ],
  ],
  'getPaths' => fn() => [],
];

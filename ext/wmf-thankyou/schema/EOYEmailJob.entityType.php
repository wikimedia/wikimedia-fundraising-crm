<?php
use CRM_WmfThankyou_ExtensionUtil as E;

return [
  'name' => 'EOYEmailJob',
  'table' => 'wmf_eoy_receipt_donor',
  'class' => 'CRM_WmfThankyou_DAO_EOYEmailJob',
  'getInfo' => fn() => [
    'title' => E::ts('EOYEmail Job'),
    'title_plural' => E::ts('EOYEmail Jobs'),
    'description' => E::ts('Tracking for EOY emails'),
    'log' => TRUE,
  ],
  'getIndices' => fn() => [
    'email_year' => [
      'fields' => [
        'email' => TRUE,
        'year' => TRUE,
      ],
    ],
    'status' => [
      'fields' => [
        'status' => TRUE,
      ],
    ],
  ],
  'getFields' => fn() => [
    'id' => [
      'title' => E::ts('EOY email job ID'),
      'sql_type' => 'int unsigned',
      'input_type' => 'Number',
      'required' => TRUE,
      'description' => E::ts('EOY email job ID'),
      'primary_key' => TRUE,
      'auto_increment' => TRUE,
    ],
    'email' => [
      'title' => E::ts('Email'),
      'sql_type' => 'varchar(254)',
      'input_type' => 'Text',
      'description' => E::ts('Email address'),
      'input_attrs' => [
        'size' => '30',
      ],
    ],
    'status' => [
      'title' => E::ts('Processing status'),
      'sql_type' => 'varchar(254)',
      'input_type' => 'Text',
      'description' => E::ts('queued|failed|sent'),
      'input_attrs' => [
        'size' => '20',
      ],
      'pseudoconstant' => [
        'callback' => 'CRM_WmfThankyou_BAO_EOYEmailJob::getStatuses',
      ],
    ],
    'year' => [
      'title' => E::ts('Send year'),
      'sql_type' => 'int',
      'input_type' => 'Text',
      'input_attrs' => [
        'size' => '20',
      ],
    ],
  ],
];

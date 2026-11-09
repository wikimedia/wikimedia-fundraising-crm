<?php

use CRM_Wmf_ExtensionUtil as E;

return [
  'name' => 'BatchAccount',
  'table' => 'civicrm_batch_account',
  'class' => 'CRM_Wmf_DAO_BatchAccount',
  'getInfo' => fn() => [
    'title' => E::ts('Batch Account'),
    'title_plural' => E::ts('Batch Accounts'),
    'description' => E::ts('Donation total journaled for each GL code & fund in an exported batch.'),
  ],
  'getFields' => fn() => [
    'id' => [
      'sql_type' => 'int unsigned',
      'required' => TRUE,
      'primary_key' => TRUE,
      'auto_increment' => TRUE,
      'input_type' => 'Number',
      'title' => E::ts('Batch Account ID'),
    ],
    'batch_id' => [
      'sql_type' => 'int unsigned',
      'required' => TRUE,
      'input_type' => 'EntityRef',
      'title' => E::ts('Batch'),
      'entity_reference' => [
        'entity' => 'Batch',
        'key' => 'id',
        'on_delete' => 'CASCADE',
      ],
    ],
    'gl_code' => [
      'sql_type' => 'varchar(64)',
      'required' => TRUE,
      'input_type' => 'Text',
      'title' => E::ts('GL Code'),
    ],
    'is_endowment' => [
      'sql_type' => 'boolean',
      'required' => TRUE,
      'default' => FALSE,
      'input_type' => 'CheckBox',
      'title' => E::ts('Is Endowment'),
      'description' => E::ts('Endowment (rather than annual fund) donations.'),
    ],
    'amount' => [
      'sql_type' => 'decimal(20,2)',
      'required' => TRUE,
      'default' => 0,
      'input_type' => 'Text',
      'data_type' => 'Money',
      'title' => E::ts('Amount'),
      'description' => E::ts('Donations less refunds, in the batch settlement currency. Negative on the reversed side of an adjustment batch.'),
    ],
    'item_count' => [
      'sql_type' => 'int',
      'required' => TRUE,
      'default' => 0,
      'input_type' => 'Number',
      'title' => E::ts('Item Count'),
      'description' => E::ts('Number of donations & refunds. Negative on the reversed side of an adjustment batch.'),
    ],
  ],
  'getIndices' => fn() => [
    'UI_batch_gl_code_endowment' => [
      'name' => 'UI_batch_gl_code_endowment',
      'fields' => [
        'batch_id' => TRUE,
        'gl_code' => TRUE,
        'is_endowment' => TRUE,
      ],
      'unique' => TRUE,
    ],
  ],
];

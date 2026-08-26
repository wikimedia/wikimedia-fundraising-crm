<?php

use CRM_Wmf_ExtensionUtil as E;

return [
  'name' => 'GatewayAccount',
  'table' => 'civicrm_gateway_account',
  'class' => 'CRM_Wmf_DAO_GatewayAccount',
  'getInfo' => fn() => [
    'title' => E::ts('Gateway Account'),
    'description' => E::ts('Financial gateway account. The entity contains information for generating financial entries'),
    'label_field' => 'label',
  ],

  'getFields' => fn() => [
    'id' => [
      'sql_type' => 'int unsigned',
      'required' => TRUE,
      'primary_key' => TRUE,
      'auto_increment' => TRUE,
      'input_type' => 'Number',
      'title' => E::ts('Gateway Account ID'),
    ],
    'name' => [
      'sql_type' => 'varchar(255)',
      'input_type' => 'Text',
      'required' => TRUE,
      'title' => E::ts('Name'),
      'description' => E::ts('Machine name for the gateway account. This maps to the value held in contribution_extra.backend_processor'),
    ],
    'gateway' => [
      'sql_type' => 'varchar(255)',
      'input_type' => 'Text',
      'title' => E::ts('Gateway'),
      'description' => E::ts('The gateway name - eg. adyen, stripe. Importantly this is stripe for both stripe and stripemg gateway accounts'),
    ],
    'label' => [
      'sql_type' => 'varchar(128)',
      'input_type' => 'Text',
      'title' => E::ts('Account Name'),
    ],
    'is_endowment' => [
      'sql_type' => 'boolean',
      'input_type' => 'CheckBox',
      'required' => TRUE,
      'default' => FALSE,
      'title' => E::ts('Is Endowment'),
      'description' => E::ts('Whether this gateway account settles to a bank account owned by the Endowment, rather than the WMF account. This describes the settlement destination for the account, not whether any given transaction is an endowment donation - a single batch can include both endowment and non-endowment contributions, but each gateway account always settles to one place.'),
    ],
    'vendor_code_foundation' => [
      'sql_type' => 'varchar(128)',
      'input_type' => 'Text',
      'title' => E::ts('Vendor Code (Foundation)'),
    ],
    'vendor_code_endowment' => [
      'sql_type' => 'varchar(128)',
      'input_type' => 'Text',
      'title' => E::ts('Vendor Code (Endowment)'),
    ],
    'balancing_account_foundation' => [
      'sql_type' => 'varchar(128)',
      'input_type' => 'Text',
      'title' => E::ts('Balancing Account (Foundation)'),
    ],
    'balancing_account_endowment' => [
      'sql_type' => 'varchar(128)',
      'input_type' => 'Text',
      'title' => E::ts('Balancing Account (Endowment)'),
    ],
    'notes' => [
      'sql_type' => 'text',
      'input_type' => 'TextArea',
      'title' => E::ts('Notes'),
    ],
  ],
  'getIndices' => fn() => [
    'index_name' => [
      'name' => 'index_name',
      'fields' => [
        'name' => TRUE,
      ],
      'unique' => TRUE,
    ],
  ],
];

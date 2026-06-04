<?php
use CRM_ExchangeRates_ExtensionUtil as E;

return [
  'name' => 'ExchangeRate',
  'table' => 'civicrm_exchange_rate',
  'class' => 'CRM_ExchangeRates_DAO_ExchangeRate',
  'getInfo' => fn() => [
    'title' => E::ts('Exchange Rate'),
    'title_plural' => E::ts('Exchange Rates'),
    'description' => E::ts('Historical exchange rates'),
    'log' => FALSE,
  ],
  'getIndices' => fn() => [
    'ExchangeRate_Currency_BankUpdate' => [
      'fields' => [
        'currency' => TRUE,
        'bank_update' => TRUE,
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
      'description' => E::ts('Unique ExchangeRate ID'),
      'primary_key' => TRUE,
      'auto_increment' => TRUE,
    ],
    'currency' => [
      'title' => E::ts('Currency'),
      'sql_type' => 'char(3)',
      'input_type' => 'Text',
      'description' => E::ts('ISO currency code'),
    ],
    'value_in_usd' => [
      'title' => E::ts('Value In Usd'),
      'sql_type' => 'double',
      'input_type' => NULL,
      'description' => E::ts('USD value of a single unit of the currency'),
    ],
    'local_update' => [
      'title' => E::ts('Local Update'),
      'sql_type' => 'timestamp',
      'input_type' => NULL,
      'description' => E::ts('Timestamp of the last update on the local side'),
      'default' => 'CURRENT_TIMESTAMP',
    ],
    'bank_update' => [
      'title' => E::ts('Bank Update'),
      'sql_type' => 'timestamp',
      'input_type' => NULL,
      'required' => TRUE,
      'description' => E::ts('Timestamp of the last update on the bank side'),
    ],
  ],
];

<?php
// Specify financial types, accounts and linking entities.
$return = [
  'refund_financial_account' => [
    'name' => 'refund_financial_account',
    'entity' => 'FinancialAccount',
    'cleanup' => 'never',
    'params' => [
      'version' => 3,
      'name' => 'Refund',
      'is_active' => 1,
      'account_type_code' => 'EXP',
    ],
  ],
  'chargeback_financial_account' => [
    'name' => 'chargeback_financial_account',
    'entity' => 'FinancialAccount',
    'cleanup' => 'never',
    'params' => [
      'version' => 3,
      'name' => 'Chargeback',
      'is_active' => 1,
      'account_type_code' => 'EXP',
    ],
  ],
];

$financialTypes = [
  'Cash',
  'Refund',
  'Chargeback',
  'Chargeback Reversal',
  'Refund Reversal',
  'Reversal',
  'Reversal Reversal',
  'Endowment Gift',
  'Stock',
  // Recurring Gift is used for the first in the series, Recurring Gift - Cash thereafter.
  'Recurring Gift',
  'Recurring Gift - Cash',
];

foreach ($financialTypes as $financialType) {
  $return[$financialType] = [
    'name' => $financialType,
    'entity' => 'FinancialType',
    'cleanup' => 'never',
    'update' => 'never',
    'params' => [
      'version' => 3,
      'name' => $financialType,
      'is_active' => 1,
      'is_deductible' => 1,
      'accounting_code' => strtoupper($financialType),
      'api.EntityFinancialAccount.create' => [
        'entity_table' => 'civicrm_financial_type',
        'account_relationship' => 'Chargeback Account is',
        'financial_account_id' => 'Chargeback',
      ],
      'api.EntityFinancialAccount.create.1' => [
        'entity_table' => 'civicrm_financial_type',
        'account_relationship' => 'Credit/Contra Revenue Account is',
        'financial_account_id' => 'Refund',
      ],
    ],
  ];
}

return $return;

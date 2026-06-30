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
  [
    'name' => 'Cash',
    'label' => 'Cash',
    'description' => '',
  ],
  [
    'name' => 'Refund',
    'label' => 'Refund',
    'description' => '',
  ],
  [
    'name' => 'Chargeback',
    'label' => 'Chargeback',
    'description' => '',
  ],
  [
    'name' => 'Chargeback Reversal',
    'label' => 'Chargeback Reversal',
    'description' => '',
  ],
  [
    'name' => 'Refund Reversal',
    'label' => 'Refund Reversal',
    'description' => '',
  ],
  [
    'name' => 'Reversal',
    'label' => 'Reversal',
    'description' => '',
  ],
  [
    'name' => 'Reversal Reversal',
    'label' => 'Reversal Reversal',
    'description' => '',
  ],
  [
    'name' => 'Endowment Gift',
    'label' => 'Endowment Gift',
    'description' => '',
  ],
  'Stock' => [
    'name' => 'Stock',
    'label' => 'Stock',
    'description' => '',
  ],
  // Recurring Gift is used for the first in the series, Recurring Gift - Cash thereafter.
  'Recurring Gift' => [
    'name' => 'Recurring Gift',
    'label' => 'Recurring Gift',
    'description' => 'First in a recurring gift series',
  ],
  'Recurring Gift - Cash' => [
    'name' => 'Recurring Gift - Cash',
    'label' => 'Recurring Gift - Cash',
    'description' => 'Subsequent gift in a recurring gift series',
  ],
  'Adjustment' => [
    'name' => 'Adjustment',
    'label' => 'Gateway Settlement Adjustment',
    'description' => 'Adjustment affecting gateway payouts. This could be money not paid out to keep our account above a threshold and should balance over time',
  ],
];

foreach ($financialTypes as $financialType) {
  $return[$financialType['name']] = [
    'name' => $financialType['name'],
    'entity' => 'FinancialType',
    'cleanup' => 'never',
    'update' => 'never',
    'params' => [
      'version' => 3,
      'name' => $financialType['name'],
      'is_active' => 1,
      'label' => $financialType['label'],
      'description' => $financialType['description'],
      'is_deductible' => 1,
      'accounting_code' => strtoupper($financialType['name']),
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

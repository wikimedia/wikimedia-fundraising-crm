<?php
// This file declares a new entity type. For more details, see "hook_civicrm_entityTypes" at:
// https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_entityTypes
return [
  [
    'name' => 'ExchangeRate',
    'class' => 'CRM_ExchangeRates_DAO_ExchangeRate',
    'table' => 'civicrm_exchange_rate',
  ],
];

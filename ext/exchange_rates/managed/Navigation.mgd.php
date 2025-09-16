<?php

use CRM_ExchangeRates_ExtensionUtil as E;

$navigation = [
  [
    'name' => 'Navigation_ExchangeRates',
    'entity' => 'Navigation',
    'cleanup' => 'always',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'label' => E::ts('Exchange Rates'),
        'name' => 'ExchangeRates',
        'url' => 'civicrm/admin/setting/exchange_rates',
        'icon' => NULL,
        'permission' => 'administer CiviCRM',
        'permission_operator' => 'AND',
        'parent_id.name' => 'CiviContribute',
        'is_active' => TRUE,
        'weight' => 1,
        'has_separator' => NULL,
        'domain_id' => 'current_domain',
      ],
    ],
  ]
];
return $navigation;

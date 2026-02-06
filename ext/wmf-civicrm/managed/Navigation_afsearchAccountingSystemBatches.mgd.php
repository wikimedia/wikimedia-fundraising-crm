<?php
use CRM_Wmf_ExtensionUtil as E;

return [
  [
    'name' => 'Navigation_afsearchAccountingSystemBatches',
    'entity' => 'Navigation',
    'cleanup' => 'unused',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'label' => E::ts('Accounting System Batches'),
        'name' => 'afsearchAccountingSystemBatches',
        'url' => 'civicrm/accounting/batches',
        'icon' => 'crm-i fa-swatchbook',
        'permission' => ['access CiviCRM'],
        'permission_operator' => 'AND',
        'parent_id.name' => 'Contributions',
        'weight' => 1,
      ],
      'match' => ['name', 'domain_id'],
    ],
  ],
];

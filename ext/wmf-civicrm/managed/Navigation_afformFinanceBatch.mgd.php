<?php
use CRM_Wmf_ExtensionUtil as E;

return [
  [
    'name' => 'Navigation_afformFinanceBatch',
    'entity' => 'Navigation',
    'cleanup' => 'unused',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'label' => E::ts('Finance Batch'),
        'name' => 'afformFinanceBatch',
        'url' => 'civicrm/finance-batch',
        'icon' => 'crm-i fa-scale-unbalanced-flip',
        'permission' => ['access CiviCRM'],
        'permission_operator' => 'AND',
        'parent_id.name' => 'Contributions',
        'weight' => 7,
      ],
      'match' => ['name', 'domain_id'],
    ],
  ],
];

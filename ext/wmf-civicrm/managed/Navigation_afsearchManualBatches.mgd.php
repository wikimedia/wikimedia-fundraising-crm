<?php
use CRM_Wmf_ExtensionUtil as E;

return [
  [
    'name' => 'Navigation_afsearchManualBatches',
    'entity' => 'Navigation',
    'cleanup' => 'unused',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'label' => E::ts('Manual Batches'),
        'name' => 'afsearchManualBatches',
        'url' => 'civicrm/manual-batches',
        'icon' => 'crm-i fa-university',
        'permission' => ['access CiviCRM'],
        'permission_operator' => 'AND',
        'parent_id.name' => 'Contributions',
        'weight' => 3,
      ],
      'match' => ['name', 'domain_id'],
    ],
  ],
];

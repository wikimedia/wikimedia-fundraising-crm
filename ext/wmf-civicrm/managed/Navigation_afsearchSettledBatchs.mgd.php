<?php
use CRM_Wmf_ExtensionUtil as E;

return [
  [
    'name' => 'Navigation_afsearchSettledBatchs',
    'entity' => 'Navigation',
    'cleanup' => 'unused',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'label' => E::ts('Settled batchs'),
        'name' => 'afsearchSettledBatchs',
        'url' => 'civicrm/contribution/settled',
        'icon' => 'crm-i fa-cash-register',
        'permission' => ['access CiviCRM'],
        'permission_operator' => 'AND',
        'parent_id.name' => 'Contributions',
        'weight' => 2,
      ],
      'match' => ['name', 'domain_id'],
    ],
  ],
];

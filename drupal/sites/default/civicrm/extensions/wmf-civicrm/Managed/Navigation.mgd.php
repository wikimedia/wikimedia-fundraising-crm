<?php
use CRM_Wmf_ExtensionUtil as E;

return [
  [
    'name' => 'Navigation_WMF_Navigation_Donor_Segments',
    'entity' => 'Navigation',
    'cleanup' => 'always',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'label' => E::ts('Donor segments & statuses'),
        'name' => 'donor_segments',
        'url' => 'civicrm/wmf-segment',
        'icon' => NULL,
        'permission' => 'access CiviCRM',
        'permission_operator' => 'AND',
        'parent_id.name' => 'Reports',
        'is_active' => TRUE,
        'weight' => 1,
        'has_separator' => NULL,
        'domain_id' => 'current_domain',
      ],
    ],
  ],
];

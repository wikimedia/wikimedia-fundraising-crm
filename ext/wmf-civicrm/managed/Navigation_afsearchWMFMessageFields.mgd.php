<?php
use CRM_Wmf_ExtensionUtil as E;
return [
  [
    'name' => 'Navigation_afsearchWMFMessageFields',
    'entity' => 'Navigation',
    'cleanup' => 'unused',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'label' => E::ts('WMF Message Fields'),
        'name' => 'afsearchWMFMessageFields',
        'url' => 'civicrm/admin/wmf-message-fields',
        'icon' => 'crm-i fa-face-grin-wink',
        'permission' => ['access CiviCRM'],
        'permission_operator' => 'AND',
        'parent_id.name' => 'WMF-admin',
        'weight' => 1,
      ],
      'match' => ['name', 'domain_id'],
    ],
  ],
];

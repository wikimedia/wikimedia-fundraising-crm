<?php
use CRM_Wmf_ExtensionUtil as E;

return [
  'type' => 'search',
  'title' => E::ts('Batch Financial Breakdown'),
  'icon' => 'fa-table-list',
  'server_route' => 'civicrm/accounting/breakdown',
  'permission' => ['access CiviContribute'],
  'navigation' => [
    'parent' => 'Contributions',
    'label' => E::ts('Batch Financial Breakdown'),
    'weight' => 4,
  ],
];

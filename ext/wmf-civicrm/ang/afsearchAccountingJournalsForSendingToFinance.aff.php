<?php
use CRM_Wmf_ExtensionUtil as E;

return [
  'type' => 'search',
  'title' => E::ts('Accounting Journals for Finance'),
  'description' => E::ts('Column selection & formatting to finance needs'),
  'icon' => 'fa-list-alt',
  'server_route' => 'civicrm/batch-finance',
  'permission' => ['administer CiviCRM data'],
  'navigation' => [
    'parent' => 'Contributions',
    'label' => E::ts('Accounting Journals for Finance'),
    'weight' => 2,
  ],
];

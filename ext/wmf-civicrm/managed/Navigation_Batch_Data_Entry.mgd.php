<?php

use Civi\Api4\Navigation;

// Core has two 'Batch Data Entry' menu items (under Contributions and
// Memberships) and parent_id.name can't tell them apart, so look up the
// Contributions one by id.
$batchDataEntryParentID = Navigation::get(FALSE)
  ->addWhere('name', '=', 'Batch Data Entry')
  ->addWhere('parent_id:name', '=', 'Contributions')
  ->addSelect('id')
  ->execute()->first()['id'] ?? NULL;

if (!$batchDataEntryParentID) {
  return [];
}

return [
  [
    'name' => 'Navigation_Stock_Batch_Data_Entry',
    'entity' => 'Navigation',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'label' => 'Stock Batch Data Entry',
        'name' => 'Stock Batch Data Entry',
        'url' => '/civicrm/search#/display/Batch_entry_Stock_/Data_entry_screen_WMF_DAF_copy_',
        'icon' => 'crm-i fa-line-chart',
        'permission' => [
          'access CiviCRM',
        ],
        'permission_operator' => 'AND',
        'parent_id' => $batchDataEntryParentID,
        'weight' => 9,
      ],
      'match' => [
        'name',
        'domain_id',
      ],
    ],
  ],
  [
    'name' => 'Navigation_General_Batch_Data_Entry',
    'entity' => 'Navigation',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'label' => 'General Batch Data Entry',
        'name' => 'General Batch Data Entry',
        'url' => '/civicrm/search#/display/Batch_entry_General_/Batch_entry_General_',
        'icon' => 'crm-i fa-keyboard-o',
        'permission' => [
          'access CiviCRM',
        ],
        'permission_operator' => 'AND',
        'parent_id' => $batchDataEntryParentID,
        'weight' => 10,
      ],
      'match' => [
        'name',
        'domain_id',
      ],
    ],
  ],
];

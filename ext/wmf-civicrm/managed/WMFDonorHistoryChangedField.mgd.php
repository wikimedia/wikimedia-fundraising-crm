<?php

use CRM_Wmf_ExtensionUtil as E;
use Civi\WMFHook\CalculatedData;

$managed = [
  [
    'name' => 'OptionGroup_wmf_donor_history_changed_field',
    'entity' => 'OptionGroup',
    'cleanup' => 'unused',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'name' => 'wmf_donor_history_changed_field',
        'title' => E::ts('WMF Donor History Changed Field'),
        'description' => E::ts('Fields tracked in wmf_donor_history.'),
        'data_type' => 'Integer',
        'is_reserved' => TRUE,
      ],
      'match' => ['name'],
    ],
  ],
];

foreach ((new CalculatedData())->getLoggedFields() as $fieldName => $field) {
  $managed[] = [
    'name' => 'OptionValue_wmf_donor_history_changed_field_' . $fieldName,
    'entity' => 'OptionValue',
    'cleanup' => 'unused',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'option_group_id.name' => 'wmf_donor_history_changed_field',
        'name' => $fieldName,
        'label' => $field['label'],
        'value' => $field['log_changes'],
        'weight' => $field['log_changes'],
      ],
      'match' => ['option_group_id', 'name'],
    ],
  ];
}

return $managed;

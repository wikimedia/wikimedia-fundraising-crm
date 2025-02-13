<?php

use CRM_LCD_MoveContrib_ExtensionUtil as E;

return [
  [
    'name' => 'OptionValue_ActivityContributionReassignment',
    'entity' => 'OptionValue',
    'cleanup' => 'unused',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'option_group_id.name' => 'activity_type',
        'label' => E::ts('Contribution Reassignment'),
        'description' => E::ts('Contribution moved to a different contact'),
        'name' => 'contribution_reassignment',
        'filter' => FALSE,
        'is_reserved' => TRUE,
        'is_active' => TRUE,
        'icon' => 'fa-truck-moving',
      ],
      'match' => [
        'option_group_id',
        'name',
      ],
    ],
  ],
];

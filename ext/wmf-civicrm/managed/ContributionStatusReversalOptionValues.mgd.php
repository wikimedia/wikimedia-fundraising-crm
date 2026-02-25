<?php
use CRM_Wmf_ExtensionUtil as E;

return [
  [
    'name' => 'OptionValue_refund_reversal',
    'entity' => 'OptionValue',
    'cleanup' => 'unused',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'option_group_id.name' => 'contribution_status',
        'label' => E::ts('Refund Reversal'),
        'description' => E::ts('Refund has been reversed by gateway'),
        'name' => 'refund_reversal',
      ],
      'match' => [
        'option_group_id',
        'name',
        'value',
      ],
    ],
  ],
  [
    'name' => 'OptionValue_chargeback_reversal',
    'entity' => 'OptionValue',
    'cleanup' => 'unused',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'option_group_id.name' => 'contribution_status',
        'label' => E::ts('Chargeback Reversal'),
        'description' => E::ts('Chargeback has been reversed by gateway'),
        'name' => 'chargeback_reversal',
      ],
      'match' => [
        'option_group_id',
        'name',
        'value',
      ],
    ],
  ],
  [
    'name' => 'OptionValue_reversal',
    'entity' => 'OptionValue',
    'cleanup' => 'unused',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'option_group_id.name' => 'contribution_status',
        'label' => E::ts('Reversal'),
        'description' => E::ts('Reversal transaction initiated by gateway'),
        'name' => 'Reversal',
      ],
      'match' => [
        'option_group_id',
        'name',
        'value',
      ],
    ],
  ],
  [
    'name' => 'OptionValue_reversal_reversal',
    'entity' => 'OptionValue',
    'cleanup' => 'unused',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'option_group_id.name' => 'contribution_status',
        'label' => E::ts('Reversal Reversal'),
        'description' => E::ts('Reversal has been reversed by gateway'),
        'name' => 'reversal_reversal',
      ],
      'match' => [
        'option_group_id',
        'name',
        'value',
      ],
    ],
  ],
];

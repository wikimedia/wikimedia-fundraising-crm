<?php

return [
  [
    'name' => 'OptionValue_BatchMode_AdjustmentBatch',
    'entity' => 'OptionValue',
    'cleanup' => 'unused',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'option_group_id.name' => 'batch_mode',
        'label' => 'Adjustment Batch',
        'value' => 3,
        'name' => 'Adjustment Batch',
        'filter' => 0,
        'is_default' => FALSE,
        'description' => 'Reverses and re-journals a single contribution whose GL code or endowment flag was corrected after its settlement batch was exported - see WMFAudit.GenerateAdjustmentBatch. Deliberately not a Manual or Automatic Batch, so the settlement export sweep leaves it alone.',
        'is_optgroup' => FALSE,
        'is_reserved' => TRUE,
        'is_active' => TRUE,
        'component_id.name' => 'CiviContribute',
        'visibility_id' => NULL,
        'icon' => 'fa-wrench',
        'color' => NULL,
      ],
      'match' => [
        'option_group_id',
        'name',
      ],
    ],
  ],
];

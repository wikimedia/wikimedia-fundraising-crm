<?php

// This file declares a managed database record of type "Job".
// The record will be automatically inserted, updated, or deleted from the
// database as appropriate. For more details, see "hook_civicrm_managed" at:
// https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_managed
return [
  [
    'name' => 'Cron:InvalidChecksum.clearexpired',
    'entity' => 'Job',
    'params' => [
      'version' => 3,
      'name' => 'Clear expired invalidated checksums',
      'description' => 'Clears invalidated checksums which have expired and so no longer need to be stored.',
      'run_frequency' => 'Daily',
      'api_entity' => 'InvalidChecksum',
      'api_action' => 'Clearexpired',
      'parameters' => '',
    ],
  ],
];

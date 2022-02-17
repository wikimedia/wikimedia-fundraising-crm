<?php
// This file declares a managed database record of type "Job".
// The record will be automatically inserted, updated, or deleted from the
// database as appropriate.
// @see https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_managed/
return [
  0 =>
    [
      'name' => 'ProcessSmashPigRecurring',
      'entity' => 'Job',
      'update' => 'never',
      'params' =>
        [
          'version' => 3,
          'name' => 'ProcessSmashPigRecurring',
          'description' => 'Process SmashPig recurring payments',
          'run_frequency' => 'Hourly',
          'api_entity' => 'Job',
          'api_action' => 'process_smashpig_recurring',
          'parameters' => 'debug=0',
        ],
    ],
];

<?php

use CRM_Monolog_ExtensionUtil as E;

return [
  [
    'name' => 'cli_std_out_logger',
    'entity' => 'Monolog',
    'cleanup' => 'never',
    'update' => 'never',
    'params' => [
      'debug' => TRUE,
      'version' => 4,
      'checkPermissions' => FALSE,
      'values' => [
        'name' => 'cli_std_out_logger',
        'type' => 'std_out',
        'channel' => 'default',
        'is_default' => TRUE,
        'is_active' => TRUE,
        'is_final' => TRUE,
        'weight' => 1,
        // Note this minimum severity can be escalated with command line switches.
        'minimum_severity' => 'warning',
        'description' => E::ts('Output to terminal for command line scripts.') . "\n" .
          E::ts('Command line options can increase (-v --verbose, -d, --debug) or decrease (-q, --quiet) the verbosity')
      ],
    ],
  ],
  [
    'name' => 'default_logger',
    'entity' => 'Monolog',
    'cleanup' => 'never',
    'update' => 'never',
    'params' => [
      'debug' => TRUE,
      'version' => 4,
      'checkPermissions' => FALSE,
      'values' => [
        'name' => 'default_logger',
        'type' => 'log_file',
        'channel' => 'default',
        'is_default' => TRUE,
        'is_active' => TRUE,
        'is_final' => FALSE,
        'weight' => 2,
        'minimum_severity' => 'debug',
        'description' => E::ts('Default log to file. File is rotated at 250MB and only 10 files are kept'),
        'configuration_options' => [
          'max_file_size' => 250,
          'max_files' => 10,
        ]
      ],
    ],
  ],
  [
    'name' => 'daily_log_file',
    'entity' => 'Monolog',
    'cleanup' => 'never',
    'update' => 'never',
    'params' => [
      'debug' => TRUE,
      'version' => 4,
      'checkPermissions' => FALSE,
      'values' => [
        'name' => 'daily_log_file',
        'channel' => 'default',
        'description' => E::ts('Log file for each day'),
        'type' => 'daily_log_file',
        'is_default' => FALSE,
        'is_active' => FALSE,
        'is_final' => FALSE,
        'weight' => 3,
        'minimum_severity' => 'debug',
        'configuration_options' => [
          'max_files' => 30,
        ],
      ],
    ],
  ],
  [
    'name' => 'firephp',
    'entity' => 'Monolog',
    'cleanup' => 'never',
    'update' => 'never',
    'params' => [
      'debug' => TRUE,
      'version' => 4,
      'checkPermissions' => FALSE,
      'values' => [
        'name' => 'firephp',
        'channel' => 'default',
        'description' => E::ts('Expose to developers using firephp (permission dependent)'),
        'type' => 'firephp',
        'is_default' => FALSE,
        'is_active' => TRUE,
        'is_final' => FALSE,
        'weight' => 4,
        'minimum_severity' => 'debug',
      ],
    ],
  ],
  [
    'name' => 'syslog',
    'entity' => 'Monolog',
    'cleanup' => 'never',
    'update' => 'never',
    'params' => [
      'debug' => TRUE,
      'version' => 4,
      'checkPermissions' => FALSE,
      'values' => [
        'name' => 'syslog',
        'description' => E::ts('log to machine syslog'),
        'channel' => 'default',
        'type' => 'syslog',
        'is_default' => FALSE,
        'is_active' => TRUE,
        'weight' => 5,
        'minimum_severity' => 'error',
        'is_final' => FALSE,
      ],
    ],
  ],
];

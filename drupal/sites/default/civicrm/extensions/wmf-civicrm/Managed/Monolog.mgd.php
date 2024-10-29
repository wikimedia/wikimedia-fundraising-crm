<?php
// Install wmf-specific display if monolog is installed.
// We will be installing monolog on live but we don't want errors if for
// any reason it is not installed (as the api will not exist if it is not).
// Note that if this extension is installed before monolog that means it won't
// add the wmf monolog on install - but it will do on cache clear
// which should happen when monolog is installed.
if (!civicrm_api3('Extension', 'getcount', [
  'full_name' => 'monolog',
  'status' => 'installed',
])) {
  return [];
}
return [
  [
    'name' => 'wmf_syslog',
    'entity' => 'Monolog',
    'cleanup' => 'never',
    'update' => 'never',
    'params' => [
      'debug' => TRUE,
      'version' => 4,
      'checkPermissions' => FALSE,
      'values' => [
        'name' => 'wmf_syslog',
        'type' => 'syslog',
        'channel' => 'wmf',
        'is_default' => TRUE,
        'is_active' => TRUE,
        'is_final' => FALSE,
        'weight' => 10,
        'minimum_severity' => 'debug',
        'description' => 'Log WMF debug to syslog',
      ],
    ],
  ],
  [
    'name' => 'wmf_cli',
    'entity' => 'Monolog',
    'cleanup' => 'never',
    'update' => 'never',
    'params' => [
      'debug' => TRUE,
      'version' => 4,
      'checkPermissions' => FALSE,
      'values' => [
        'name' => 'wmf_cli',
        'type' => 'std_out',
        'channel' => 'wmf',
        'is_default' => FALSE,
        'is_active' => TRUE,
        'is_final' => FALSE,
        'weight' => 1,
        // Note this minimum severity can be escalated with command line switches.
        'minimum_severity' => 'warning',
        'description' => ('Output to terminal for command line scripts.') . "\n" .
          ('Command line options can increase (-v --verbose, -d, --debug) or decrease (-q, --quiet) the verbosity'),
      ],
    ],
  ],
  [
    'name' => 'acoustic_info',
    'entity' => 'Monolog',
    'cleanup' => 'never',
    'update' => 'always',
    'params' => [
      'debug' => TRUE,
      'version' => 4,
      'checkPermissions' => FALSE,
      'values' => [
        'name' => 'acoustic_info',
        'type' => 'mail',
        'channel' => 'Silverpop',
        'is_default' => FALSE,
        'is_active' => TRUE,
        'is_final' => FALSE,
        'weight' => 1,
        'minimum_severity' => 'info',
        'description' => ('Send emails on acoustic job progress.'),
        'configuration_options' => [
          'to' => \Civi::settings()->get('wmf_acoustic_notice_recipient'),
          'from' => \Civi::settings()->get('wmf_failmail_from'),
          'subject' => 'Acoustic job %context.job_id% status %context.type%',
        ],
      ],
    ],
  ],
];

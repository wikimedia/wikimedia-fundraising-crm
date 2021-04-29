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
];

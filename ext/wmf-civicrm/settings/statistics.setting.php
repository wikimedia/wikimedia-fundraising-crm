<?php

use CRM_Wmf_ExtensionUtil as E;

return [
  'metrics_reporting_prometheus_path' => [
    'name' => 'metrics_reporting_prometheus_path',
    'title' => E::ts('Prometheus Path'),
    'description' => E::ts('The full path to the directory where we should write Prometheus metrics files.'),
    'help_text' => '',
    'html_type' => 'text',
    'type' => 'String',
    'is_domain' => 1,
    'default' => '/var/spool/prometheus',
    'is_contact' => 0,
    'html_attributes' => [
      'size' => 80,
    ],
    'settings_pages' => ['wmf-civicrm' => ['weight' => 300]],
  ],
];

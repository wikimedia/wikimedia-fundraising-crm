<?php

use CRM_Wmf_ExtensionUtil as E;

return [
  'wmf_audit_directory_payments_log' => [
    'name' => 'wmf_audit_directory_payments_log',
    'title' => E::ts('Directory for downloaded payment files'),
    'description' => '',
    'help_text' => '',
    'html_type' => 'text',
    'type' => 'String',
    'is_domain' => 1,
    'default' => '/srv/archive/frlog/logs/',
    'is_contact' => 0,
    'html_attributes' => [
      'size' => 80,
    ],
    'settings_pages' => ['wmf-audit' => ['weight' => 200]],
  ],
  'wmf_audit_directory_working_log' => [
    'name' => 'wmf_audit_directory_working_log',
    'title' => E::ts('Working directory for audit process'),
    'description' => E::ts('Files are copied here & unzipped for parsing'),
    'help_text' => '',
    'default' => '/var/log/fundraising',
    'html_type' => 'text',
    'type' => 'String',
    'is_domain' => 1,
    'is_contact' => 0,
    'html_attributes' => [
      'size' => 80,
    ],
    'settings_pages' => ['wmf-audit' => ['weight' => 210]],
  ],
  'wmf_audit_directory_audit' => [
    'name' => 'wmf_audit_directory_audit',
    'title' => E::ts('Directory where filed being processed are handled'),
    'description' => E::ts('within this directory files are sorted into incoming and completed'),
    'help_text' => '',
    'default' => '/var/spool/audit/',
    'html_type' => 'text',
    'type' => 'String',
    'is_domain' => 1,
    'is_contact' => 0,
    'html_attributes' => [
      'size' => 80,
    ],
    'settings_pages' => ['wmf-audit' => ['weight' => 220]],
  ],
];

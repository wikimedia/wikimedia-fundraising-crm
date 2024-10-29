<?php

use CRM_Wmf_ExtensionUtil as E;

return [
  'wmf_failmail_recipient' => [
    'name' => 'wmf_failmail_recipient',
    'title' => E::ts('Failmail Recipient Address'),
    'description' => E::ts('Enter the failmail contact address.'),
    'help_text' => '',
    'html_type' => 'text',
    'type' => 'String',
    'is_domain' => 1,
    'default' => 'fr-tech@wikimedia.org',
    'is_contact' => 0,
    'html_attributes' => [
      'size' => 80,
    ],
    'settings_pages' => ['wmf-queue' => ['weight' => 10]],
  ],
  'wmf_failmail_exclude_list' => [
    'name' => 'wmf_failmail_exclude_list',
    'title' => E::ts('Failmail Message Exceptions for Email'),
    'description' => E::ts('Comma-delimited (no spaces) list of donor email addresses that will never trigger failmail'),
    'help_text' => '',
    'html_type' => 'text',
    'type' => 'String',
    'is_domain' => 1,
    'is_contact' => 0,
    'html_attributes' => [
      'size' => 80,
    ],
    'settings_pages' => ['wmf-queue' => ['weight' => 20]],
  ],
  'wmf_failmail_from' => [
    'name' => 'wmf_failmail_from',
    'title' => E::ts('FailMail from address'),
    'description' => '',
    'help_text' => '',
    'default' => 'fr-tech@wikimedia.org',
    'html_type' => 'text',
    'type' => 'String',
    'is_domain' => 1,
    'is_contact' => 0,
    'html_attributes' => [
      'size' => 80,
    ],
    'settings_pages' => ['wmf-queue' => ['weight' => 30]],
  ],
  'wmf_acoustic_notice_recipient' => [
    'name' => 'wmf_acoustic_notice_recipient',
    'title' => E::ts('Acoustic Notice Recipient Address'),
    'description' => E::ts('Acoustic Notice contact address.'),
    'help_text' => '',
    'html_type' => 'text',
    'type' => 'String',
    'is_domain' => 1,
    'default' => 'fundraisingemail@wikimedia.org',
    'is_contact' => 0,
    'html_attributes' => [
      'size' => 80,
    ],
    'settings_pages' => ['wmf-queue' => ['weight' => 10]],
  ],
  'wmf_requeue_delay' => [
    'group_name' => 'wmf Settings',
    'group' => 'wmf',
    'name' => 'wmf_requeue_delay',
    'title' => E::ts('Requeue Delay'),
    'default' => 1200,
    'type' => 'Integer',
    'is_domain' => 1,
    'is_contact' => 0,
    'description' => E::ts(
      'Number of seconds to wait before trying a re-queueable failed message again'
    ),
    'html_type' => 'number',
    'html_attributes' => [
      'size' => '5',
    ],
    'settings_pages' => ['wmf-queue' => ['weight' => 40]],
  ],
  'wmf_requeue_max' => [
    'group_name' => 'wmf Settings',
    'group' => 'wmf',
    'name' => 'wmf_requeue_max',
    'title' => E::ts('Maximum retries'),
    'default' => 10,
    'type' => 'Integer',
    'is_domain' => 1,
    'is_contact' => 0,
    'description' => E::ts(
      'Maximum number of times to re-queue a failed message'
    ),
    'html_type' => 'number',
    'html_attributes' => [
      'size' => '5',
    ],
    'settings_pages' => ['wmf-queue' => ['weight' => 50]],
  ],
];

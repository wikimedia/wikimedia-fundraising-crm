<?php
use CRM_Wmf_ExtensionUtil as E;

return [
  'wmf_unsubscribe_url' => [
    'group_name' => 'wmf Settings',
    'group' => 'wmf',
    'name' => 'wmf_unsubscribe_url',
    'default' => 'https://payments.wikimedia.org/index.php/Special:FundraiserUnsubscribe',
    'type' => 'String',
    'is_domain' => 1,
    'is_contact' => 0,
    'title' => E::ts('Unsubscribe base url'),
    'help_text' => '',
    'html_type' => 'text',
    'html_attributes' => [
      'size' => 80,
    ],
    'settings_pages' => ['wmf-civicrm' => ['weight' => 20]],
  ],
  'wmf_email_preferences_url' => [
    'group_name' => 'wmf Settings',
    'group' => 'wmf',
    'name' => 'wmf_email_preferences_url',
    'default' => 'https://fundraising.wikimedia.org/wiki/index.php?title=Special:EmailPreferences/emailPreferences',
    'type' => 'String',
    'is_domain' => 1,
    'is_contact' => 0,
    'title' => E::ts('Email preferences base url'),
    'help_text' => '',
    'html_type' => 'text',
    'html_attributes' => [
      'size' => 100,
    ],
    'settings_pages' => ['wmf-civicrm' => ['weight' => 30]],
  ],
  'wmf_last_delete_deleted_contact_modified_date' => [
    'group_name' => 'wmf Settings',
    'group' => 'wmf',
    'name' => 'wmf_last_delete_deleted_contact_modified_date',
    'default' => null,
    'type' => 'Date',
    'quick_form_type' => 'DateTime',
    'is_domain' => 1,
    'is_contact' => 0,
    'title' => E::ts('Last delete deleted contact\'s modified date'),
    'help_text' => '',
    'html_type' => 'text',
    'html_attributes' => [
      'size' => 20,
    ],
    'settings_pages' => ['wmf-civicrm' => ['weight' => 40]],
  ]
];

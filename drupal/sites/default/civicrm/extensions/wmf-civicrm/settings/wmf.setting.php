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
  ],
  'wmf_save_process_greetings_on_create' => [
    'group_name' => 'wmf Settings',
    'group' => 'wmf',
    'name' => 'wmf_save_process_greetings_on_create',
    'default' => 1,
    'type' => 'Boolean',
    'quick_form_type' => 'YesNo',
    'is_domain' => 1,
    'is_contact' => 0,
    'title' => E::ts('Should greeting processing be done during create'),
    'help_text' => '',
    'html_type' => 'radio',
    'settings_pages' => ['wmf-civicrm' => ['weight' => 50]],
  ],
  //
  'wmf_refund_alert_factor' => [
    'group_name' => 'wmf Settings',
    'group' => 'wmf',
    'name' => 'wmf_refund_alert_factor',
    'default' => 0.02,
    'type' => 'Float',
    'is_domain' => 1,
    'is_contact' => 0,
    'title' => E::ts('Refund discrepancy alert factor'),
    'help_text' => E::ts(
      'Send a failmail when a refund differs from the original contribution by more than this factor'
    ),
    'html_type' => 'number',
    'html_attributes' => [
      'size' => '5',
    ],
    // FixCivi: these would make a lot more sense under 'html_attributes' but you'd have to take that up
    // with SettingsTrait::addFieldsDefinedInSettingsMetadata which passes the 'options' to the same
    // class's add function as its $attributes argument.
    'options' => [
      'step' => '0.01',
      'min' => '0',
      'max' => '1',
    ],
    'settings_pages' => ['wmf-civicrm' => ['weight' => 60]],
   ],
];

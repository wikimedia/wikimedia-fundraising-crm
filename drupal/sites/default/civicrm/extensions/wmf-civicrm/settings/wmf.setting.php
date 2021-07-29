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
];

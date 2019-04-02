<?php
use CRM_Thethe_ExtensionUtil as E;

return [
  'thethe_org_prefix_strings' => [
    'group' => 'thethe',
    'name' => 'thethe_org_prefix_strings',
    'type' => 'String',
    'is_domain' => 1,
    'is_contact' => 0,
    'description' => E::ts('Enter a comma separated list of strings in single quotes. Remember to put a space inside the string if you want one. Strings are case insensitive'),
    'title' => E::ts('Strings to remove from the start'),
    'help_text' => E::ts('Enter a comma separated list of strings in single quotes. Remember to put a space inside the string if you want one. Strings are case insensitive'),
    'html_type' => 'text',
    'html_attributes' => [
      'size' => 50,
    ],
    'default' => "'The '",
    'settings_pages' => ['thethe' => ['weight' => 20], 'search' => ['weight' => 20]],
  ],
  'thethe_org_suffix_strings' => [
    'group' => 'thethe',
    'name' => 'thethe_org_suffix_strings',
    'type' => 'String',
    'is_domain' => 1,
    'is_contact' => 0,
    'description' => E::ts('Strings to be removed from the end of the sort_name'),
    'title' => E::ts('Strings to remove from the end'),
    'help_text' => E::ts('Enter a comma separated list of strings in single quotes. Remember to put a space inside the string if you want one'),
    'html_type' => 'text',
    'html_attributes' => [
      'size' => 50,
    ],
    'settings_pages' => ['thethe' => ['weight' => 30], 'search' => ['weight' => 30]],
  ],
  'thethe_org_anywhere_strings' => [
    'group' => 'thethe',
    'name' => 'thethe_org_anywhere_strings',
    'type' => 'String',
    'is_domain' => 1,
    'is_contact' => 0,
    'description' => E::ts('Strings to be removed from anywhere in the sort_name'),
    'title' => E::ts('Strings to remove from anywhere'),
    'help_text' => E::ts('Be careful with this setting! Enter a comma separated list of strings in single quotes. Remember to put a space inside the quotes if you want one'),
    'html_type' => 'text',
    'html_attributes' => [
      'size' => 50,
    ],
    'settings_pages' => ['thethe' => ['weight' => 40], 'search' => ['weight' => 40]],
  ],
];

<?php

use CRM_Theisland_ExtensionUtil as E;

$settings = [];

if (CIVICRM_UF == 'WordPress') {
  $settings['theisland_hide_wp_menubar'] = [
    'name' => 'theisland_hide_wp_menubar',
    'type' => 'Boolean',
    'default' => TRUE,
    'html_type' => 'checkbox',
    'add' => 1.0,
    'title' => E::ts('Hide the WordPress menubar when in CiviCRM'),
    'is_domain' => 1,
    'is_contact' => 0,
    'description' => E::ts('When logged-in as an editor or administrator, the WordPress menubar can be a bit of a distraction. It can still be displayed again by clicking on the CiviCRM logo in the top menu, then clicking on "Hide the menu".'),
    'settings_pages' => ['theisland' => ['weight' => 10]],
  ];
}

$settings['theisland_disable_bootstrap_js'] = [
  'name' => 'theisland_disable_bootstrap_js',
  'type' => 'Boolean',
  'default' => FALSE,
  'html_type' => 'checkbox',
  'add' => 1.0,
  'title' => E::ts('Disable loading Bootstrap Javascript'),
  'is_domain' => 1,
  'is_contact' => 0,
  'description' => E::ts('If you have another source for the Bootrstrap javascript files, you can disable loading the copy provided by this theme.'),
  'settings_pages' => ['theisland' => ['weight' => 20]],
];

return $settings;

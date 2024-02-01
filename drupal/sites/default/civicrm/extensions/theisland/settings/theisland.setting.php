<?php

use CRM_Theisland_ExtensionUtil as E;

return [
  'theisland_disable_bootstrap_js' => [
    'name' => 'theisland_disable_bootstrap_js',
    'type' => 'Boolean',
    'default' => FALSE,
    'html_type' => 'checkbox',
    'add' => 1.0,
    'title' => E::ts('Disable loading Bootstrap Javascript'),
    'is_domain' => 1,
    'is_contact' => 0,
    'description' => E::ts('If you have another source for the Bootrstrap javascript files, you can disable loading the copy provided by this theme.'),
    'settings_pages' => ['theisland' => ['weight' => 10]],
  ],
];

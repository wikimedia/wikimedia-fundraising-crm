<?php
use CRM_Wmf_ExtensionUtil as E;

return [
  'type' => 'form',
  'title' => E::ts('Add Appeal'),
  'icon' => 'fa-bullhorn',
  'server_route' => 'civicrm/appeal/add',
  'permission' => ['access CiviCRM'],
  'navigation' => [
    'parent' => 'WMF-admin',
    'label' => E::ts('Add Appeal'),
    'weight' => 102,
  ],
  'confirmation_type' => 'show_confirmation_message',
  'confirmation_message' => 'Appeal [OptionValue1.0.label] added.',
];

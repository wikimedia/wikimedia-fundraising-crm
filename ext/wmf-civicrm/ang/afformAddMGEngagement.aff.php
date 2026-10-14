<?php
use CRM_Wmf_ExtensionUtil as E;

return [
  'type' => 'form',
  'title' => E::ts('Add MG Engagement'),
  'icon' => 'fa-handshake-o',
  'server_route' => 'civicrm/mg-engagement/add',
  'permission' => ['access CiviCRM'],
  'requires' => ['wmfMGEngagement'],
];

<?php
use CRM_Wmf_ExtensionUtil as E;
return [
  'type' => 'search',
  'title' => E::ts('WMF Message Fields'),
  'description' => E::ts('Library of fields supported by the WMF Message classes (fr-tech audience)'),
  'icon' => 'fa-face-grin-wink',
  'server_route' => 'civicrm/admin/wmf-message-fields',
];

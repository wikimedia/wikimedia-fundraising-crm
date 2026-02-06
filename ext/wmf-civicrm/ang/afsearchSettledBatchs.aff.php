<?php
use CRM_Wmf_ExtensionUtil as E;

return [
  'type' => 'search',
  'title' => E::ts('Settled batchs'),
  'icon' => 'fa-cash-register',
  'server_route' => 'civicrm/contribution/settled',
];

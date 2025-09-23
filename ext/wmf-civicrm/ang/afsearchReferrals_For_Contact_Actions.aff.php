<?php
use CRM_Wmf_ExtensionUtil as E;

return [
  'type' => 'search',
  'title' => E::ts('Referrals'),
  'icon' => 'fa-fire-flame-simple',
  'server_route' => 'civicrm/referralspercontact',
  'is_public' => TRUE,
];

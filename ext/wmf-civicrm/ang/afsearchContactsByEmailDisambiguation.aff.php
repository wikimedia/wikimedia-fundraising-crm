<?php
use CRM_Wmf_ExtensionUtil as E;

return [
  'type' => 'search',
  'title' => E::ts('Contacts by email disambiguation'),
  'icon' => 'fa-list-alt',
  'server_route' => 'civicrm/contactsbyemail',
];

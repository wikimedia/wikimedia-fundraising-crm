<?php
use CRM_Wmf_ExtensionUtil as E;

return [
  'type' => 'search',
  'title' => E::ts('Payment Attempt'),
  'icon' => 'fa-credit-card',
  'server_route' => 'civicrm/paymentattemptsingle',
];

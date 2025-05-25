<?php

$processors = [
  'adyen',
  'amazon',
  'braintree',
  'dlocal',
  'fundraiseup',
  'globalcollect',
  'gravy',
  'ingenico',
  'paypal',
  'paypal_ec'
];
$return = [];
foreach ($processors as $name) {
  $return[$name] = [
    'name' => $name,
    'entity' => 'PaymentProcessor',
    'cleanup' => 'never',
    'params' => [
      'version' => 3,
      'name' => $name,
      'payment_processor_type_id' => 'smashpig_' . $name,
    ],
  ];
  $return[$name . '_test'] = [
    'name' => $name . '_test',
    'entity' => 'PaymentProcessor',
    'cleanup' => 'never',
    'params' => [
      'version' => 3,
      'name' => $name,
      'is_test' => TRUE,
      'payment_processor_type_id' => 'smashpig_' . $name,
    ],
  ];
}
return $return;

<?php
/**
 * Coped / modified from
 * https://github.com/eileenmcnaughton/nz.co.fuzion.omnipaymultiprocessor/blob/master/Metadata/processors.mgd.php
 *
 * To add a new processor you need to add an item to this array. The settings
 * are generally dummies - ['params']['name'] is used to determine which of the
 * processors in the SmashPig settings are instantiated. The 'smashpig_' prefix
 * is removed, and the resulting value is used to initiate a SmashPig context
 * with ProviderConfiguration::createForProvider.
 *
 * The record will be automatically inserted, updated, or deleted from the
 * database as appropriate. For more details, see "hook_civicrm_managed" at:
 * http://wiki.civicrm.org/confluence/display/CRMDOC/Hook+Reference
 */
return [
  0 => [
    'name' => 'SmashPig - Adyen',
    'entity' => 'payment_processor_type',
    'params' => [
      'version' => 3,
      'title' => 'Adyen via SmashPig',
      'name' => 'smashpig_adyen',
      'description' => 'SmashPig Adyen Processor',
      'user_name_label' => 'Unused',
      'password_label' => 'Unused',
      'signature_label' => 'Unused',
      'subject_label' => 'Unused',
      'class_name' => 'Payment_SmashPig',
      'url_site_default' => 'https://dummyurl.com',
      'url_api_default' => 'https://dummyurl.com',
      'billing_mode' => 1,
      'payment_type' => 1,
    ],
  ],
  1 => [
    'name' => 'SmashPig - Amazon Pay',
    'entity' => 'payment_processor_type',
    'params' => [
      'version' => 3,
      'title' => 'Amazon Pay via SmashPig',
      'name' => 'smashpig_amazon',
      'description' => 'SmashPig Amazon Pay Processor',
      'user_name_label' => 'Unused',
      'password_label' => 'Unused',
      'signature_label' => 'Unused',
      'subject_label' => 'Unused',
      'class_name' => 'Payment_SmashPig',
      'url_site_default' => 'https://dummyurl.com',
      'url_api_default' => 'https://dummyurl.com',
      'billing_mode' => 1,
      'payment_type' => 1,
    ],
  ],
  2 => [
    'name' => 'SmashPig - D*Local',
    'entity' => 'payment_processor_type',
    'params' => [
      'version' => 3,
      'title' => 'D*Local via SmashPig',
      'name' => 'smashpig_dlocal',
      'description' => 'SmashPig D*Local Processor',
      'user_name_label' => 'Unused',
      'password_label' => 'Unused',
      'signature_label' => 'Unused',
      'subject_label' => 'Unused',
      'class_name' => 'Payment_SmashPig',
      'url_site_default' => 'https://dummyurl.com',
      'url_api_default' => 'https://dummyurl.com',
      'billing_mode' => 1,
      'payment_type' => 1,
    ],
  ],
  3 => [
    'name' => 'SmashPig - GlobalCollect',
    'entity' => 'payment_processor_type',
    'params' => [
      'version' => 3,
      'title' => 'GlobalCollect via SmashPig',
      'name' => 'smashpig_globalcollect',
      'description' => 'SmashPig GlobalCollect Processor',
      'user_name_label' => 'Unused',
      'password_label' => 'Unused',
      'signature_label' => 'Unused',
      'subject_label' => 'Unused',
      'class_name' => 'Payment_SmashPig',
      'url_site_default' => 'https://dummyurl.com',
      'url_api_default' => 'https://dummyurl.com',
      'billing_mode' => 1,
      'payment_type' => 1,
    ],
  ],
  4 => [
    'name' => 'SmashPig - Ingenico Connect',
    'entity' => 'payment_processor_type',
    'params' => [
      'version' => 3,
      'title' => 'Ingenico Connect via SmashPig',
      'name' => 'smashpig_ingenico',
      'description' => 'SmashPig Ingenico Connect Processor',
      'user_name_label' => 'Unused',
      'password_label' => 'Unused',
      'signature_label' => 'Unused',
      'subject_label' => 'Unused',
      'class_name' => 'Payment_SmashPig',
      'url_site_default' => 'https://dummyurl.com',
      'url_api_default' => 'https://dummyurl.com',
      'billing_mode' => 1,
      'payment_type' => 1,
    ],
  ],
  5 => [
    'name' => 'SmashPig - PayPal',
    'entity' => 'payment_processor_type',
    'params' => [
      'version' => 3,
      'title' => 'PayPal via SmashPig',
      'name' => 'smashpig_paypal',
      'description' => 'SmashPig PayPal Processor',
      'user_name_label' => 'Unused',
      'password_label' => 'Unused',
      'signature_label' => 'Unused',
      'subject_label' => 'Unused',
      'class_name' => 'Payment_SmashPig',
      'url_site_default' => 'https://dummyurl.com',
      'url_api_default' => 'https://dummyurl.com',
      'billing_mode' => 1,
      'payment_type' => 1,
    ],
  ],
  6 => [
    'name' => 'SmashPig - PayPal Express Checkout',
    'entity' => 'payment_processor_type',
    'params' => [
      'version' => 3,
      'title' => 'PayPal Express Checkout via SmashPig',
      'name' => 'smashpig_paypal_ec',
      'description' => 'SmashPig Ingenico Connect Processor',
      'user_name_label' => 'Unused',
      'password_label' => 'Unused',
      'signature_label' => 'Unused',
      'subject_label' => 'Unused',
      'class_name' => 'Payment_SmashPig',
      'url_site_default' => 'https://dummyurl.com',
      'url_api_default' => 'https://dummyurl.com',
      'billing_mode' => 1,
      'payment_type' => 1,
    ],
  ],
  7 => [
    'name' => 'SmashPig - Braintree',
    'entity' => 'payment_processor_type',
    'params' => [
      'version' => 3,
      'title' => 'Braintree via SmashPig',
      'name' => 'smashpig_braintree',
      'description' => 'SmashPig Braintree Processor',
      'user_name_label' => 'Unused',
      'password_label' => 'Unused',
      'signature_label' => 'Unused',
      'subject_label' => 'Unused',
      'class_name' => 'Payment_SmashPig',
      'url_site_default' => 'https://dummyurl.com',
      'url_api_default' => 'https://dummyurl.com',
      'billing_mode' => 1,
      'payment_type' => 1,
    ],
  ],
  8 => [
    'name' => 'SmashPig - Fundraiseup',
    'entity' => 'payment_processor_type',
    'params' => [
      'version' => 3,
      'title' => 'Fundraiseup via SmashPig',
      'name' => 'smashpig_fundraiseup',
      'description' => 'SmashPig Fundraiseup Processor',
      'user_name_label' => 'Unused',
      'password_label' => 'Unused',
      'signature_label' => 'Unused',
      'subject_label' => 'Unused',
      'class_name' => 'Payment_SmashPig',
      'url_site_default' => 'https://dummyurl.com',
      'url_api_default' => 'https://dummyurl.com',
      'billing_mode' => 1,
      'payment_type' => 1,
    ],
  ],
  9 => [
    'name' => 'SmashPig - Gravy',
    'entity' => 'payment_processor_type',
    'params' => [
      'version' => 3,
      'title' => 'Gravy via SmashPig',
      'name' => 'smashpig_gravy',
      'description' => 'SmashPig Gravy Processor',
      'user_name_label' => 'Unused',
      'password_label' => 'Unused',
      'signature_label' => 'Unused',
      'subject_label' => 'Unused',
      'class_name' => 'Payment_SmashPig',
      'url_site_default' => 'https://dummyurl.com',
      'url_api_default' => 'https://dummyurl.com',
      'billing_mode' => 1,
      'payment_type' => 1,
    ],
  ],
];

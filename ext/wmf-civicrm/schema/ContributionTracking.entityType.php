<?php
use CRM_Wmf_ExtensionUtil as E;
return [
  'name' => 'ContributionTracking',
  'table' => 'civicrm_contribution_tracking',
  'class' => 'CRM_Wmf_DAO_ContributionTracking',
  'getInfo' => fn() => [
    'title' => E::ts('Contribution Tracking'),
    'title_plural' => E::ts('Contribution Trackings'),
    'description' => E::ts('CiviCRM Contribution Tracking table'),
    'add' => '5.61',
  ],
  'getIndices' => fn() => [
    'currency' => [
      'fields' => [
        'currency' => TRUE,
      ],
    ],
    'utm_medium_id' => [
      'fields' => [
        'utm_medium' => TRUE,
      ],
    ],
    'utm_campaign_id' => [
      'fields' => [
        'utm_campaign' => TRUE,
      ],
    ],
    'banner' => [
      'fields' => [
        'banner' => TRUE,
      ],
    ],
    'landing_page' => [
      'fields' => [
        'landing_page' => TRUE,
      ],
    ],
    'payment_method_id' => [
      'fields' => [
        'payment_method_id' => TRUE,
      ],
    ],
    'language' => [
      'fields' => [
        'language' => TRUE,
      ],
    ],
    'country' => [
      'fields' => [
        'country' => TRUE,
      ],
    ],
    'tracking_date' => [
      'fields' => [
        'tracking_date' => TRUE,
      ],
    ],
    'index_mailing_identifier' => [
      'fields' => [
        'mailing_identifier' => TRUE,
      ],
    ],
    'banner_history_log_id' => [
      'fields' => [
        'banner_history_log_id' => TRUE,
      ],
    ],
    'screen_width' => [
      'fields' => [
        'screen_width' => TRUE,
      ],
    ],
    'screen_height' => [
      'fields' => [
        'screen_height' => TRUE,
      ],
    ],
  ],
  'getFields' => fn() => [
    'id' => [
      'title' => E::ts('ID'),
      'sql_type' => 'int unsigned',
      'input_type' => 'Number',
      'required' => TRUE,
      'description' => E::ts('Unique Contribution Tracking ID'),
      'primary_key' => TRUE,
    ],
    'contribution_id' => [
      'title' => E::ts('Contribution ID'),
      'sql_type' => 'int unsigned',
      'input_type' => 'EntityRef',
      'description' => E::ts('FK to Contribution'),
      'input_attrs' => [
        'label' => ts('Contribution'),
      ],
      'entity_reference' => [
        'entity' => 'Contribution',
        'key' => 'id',
        'on_delete' => 'SET NULL',
      ],
    ],
    'amount' => [
      'title' => E::ts('Amount'),
      'sql_type' => 'decimal(20,3)',
      'input_type' => 'Decimal',
      'description' => E::ts('Amount'),
    ],
    'currency' => [
      'title' => E::ts('Currency'),
      'sql_type' => 'varchar(3)',
      'input_type' => 'text',
      'description' => E::ts('Currency ISO Code'),
    ],
    'usd_amount' => [
      'title' => E::ts('Usd Amount'),
      'sql_type' => 'decimal(20,2)',
      'input_type' => 'Decimal',
      'description' => E::ts('USD Amount'),
    ],
    'is_recurring' => [
      'title' => E::ts('Is Recurring?'),
      'sql_type' => 'boolean',
      'input_type' => 'Boolean',
    ],
    'referrer' => [
      'title' => E::ts('Referrer'),
      'sql_type' => 'varchar(4096)',
      'input_type' => 'Text',
      'description' => E::ts('Referrer'),
    ],
    'utm_medium' => [
      'title' => E::ts('Utm Medium'),
      'sql_type' => 'varchar(128)',
      'input_type' => 'Text',
      'description' => E::ts('UTM Medium'),
    ],
    'utm_campaign' => [
      'title' => E::ts('Utm Campaign'),
      'sql_type' => 'varchar(128)',
      'input_type' => 'Text',
      'description' => E::ts('UTM Campaign'),
    ],
    'utm_key' => [
      'title' => E::ts('Utm Key'),
      'sql_type' => 'varchar(128)',
      'input_type' => 'Text',
      'description' => E::ts('UTM Key'),
    ],
    'gateway' => [
      'title' => E::ts('Gateway'),
      'sql_type' => 'varchar(32)',
      'input_type' => 'Text',
      'description' => E::ts('Gateway, e.g paypal_ec,adyen'),
    ],
    'appeal' => [
      'title' => E::ts('Appeal'),
      'sql_type' => 'varchar(64)',
      'input_type' => 'Text',
      'description' => E::ts('e.g JimmyQuote - the appeal is the text to the left of the input boxes (on desktop-size screens)'),
    ],
    'payments_form_variant' => [
      'title' => E::ts('Payments Form Variant'),
      'sql_type' => 'varchar(64)',
      'input_type' => 'Text',
      'description' => E::ts('a \'variant\' generally changes something about the input boxes or their labels'),
    ],
    'banner' => [
      'title' => E::ts('Banner'),
      'sql_type' => 'varchar(128)',
      'input_type' => 'Text',
      'description' => E::ts('Banner'),
    ],
    'landing_page' => [
      'title' => E::ts('Landing Page'),
      'sql_type' => 'varchar(128)',
      'input_type' => 'Text',
      'description' => E::ts('Landing Page'),
    ],
    'payment_method_id' => [
      'title' => E::ts('Payment Method Family'),
      'sql_type' => 'int',
      'input_type' => 'Select',
      'readonly' => TRUE,
      'pseudoconstant' => [
        'option_group_name' => 'payment_method',
      ],
    ],
    'payment_submethod_id' => [
      'title' => E::ts('Specific Payment Method'),
      'sql_type' => 'int',
      'input_type' => 'Select',
      'readonly' => TRUE,
      'pseudoconstant' => [
        'option_group_name' => 'payment_instrument',
      ],
    ],
    'language' => [
      'title' => E::ts('Language'),
      'sql_type' => 'varchar(8)',
      'input_type' => 'Text',
      'description' => E::ts('Language'),
    ],
    'country' => [
      'title' => E::ts('Country'),
      'sql_type' => 'varchar(2)',
      'input_type' => 'Text',
      'description' => E::ts('Country'),
    ],
    'tracking_date' => [
      'title' => E::ts('Tracking Date'),
      'sql_type' => 'datetime',
      'input_type' => 'Select Date',
      'description' => E::ts('Tracking Date'),
    ],
    'os' => [
      'title' => E::ts('Os'),
      'sql_type' => 'varchar(255)',
      'input_type' => 'Text',
      'description' => E::ts('Operating System'),
    ],
    'os_version' => [
      'title' => E::ts('Os Version'),
      'sql_type' => 'varchar(255)',
      'input_type' => 'Text',
      'description' => E::ts('Operating System - Major Version'),
    ],
    'browser' => [
      'title' => E::ts('Browser'),
      'sql_type' => 'varchar(255)',
      'input_type' => 'Text',
      'description' => E::ts('Browser'),
    ],
    'browser_version' => [
      'title' => E::ts('Browser Version'),
      'sql_type' => 'varchar(255)',
      'input_type' => 'Text',
      'description' => E::ts('Browser Version'),
    ],
    'recurring_choice_id' => [
      'title' => E::ts('Recurring Choice'),
      'sql_type' => 'int',
      'input_type' => 'Select',
      'readonly' => TRUE,
      'description' => E::ts('Denotes whether a recurring donation was the result of upsell or an organic recurring transaction'),
      'pseudoconstant' => [
        'option_group_name' => 'recurring_choice',
      ],
    ],
    'device_type_id' => [
      'title' => E::ts('Device Type'),
      'sql_type' => 'int',
      'input_type' => 'Select',
      'readonly' => TRUE,
      'description' => E::ts('The device the banner was served to (e.g Desktop or Mobile)'),
      'pseudoconstant' => [
        'option_group_name' => 'device_type',
      ],
    ],
    'banner_size_id' => [
      'title' => E::ts('Banner Size'),
      'sql_type' => 'int',
      'input_type' => 'Select',
      'readonly' => TRUE,
      'description' => E::ts('Large or small banner'),
      'pseudoconstant' => [
        'option_group_name' => 'banner_size',
      ],
    ],
    'is_test_variant' => [
      'title' => E::ts('Is a test variant'),
      'sql_type' => 'boolean',
      'input_type' => 'CheckBox',
      'description' => E::ts('Test, rather than a control group'),
    ],
    'banner_variant' => [
      'title' => E::ts('Banner Variant'),
      'sql_type' => 'varchar(128)',
      'input_type' => 'Text',
      'readonly' => TRUE,
      'description' => E::ts('The name of the tested variant (if not control)'),
    ],
    'is_pay_fee' => [
      'title' => E::ts('User opted to pay fee (PTF)'),
      'sql_type' => 'boolean',
      'input_type' => 'CheckBox',
      'description' => E::ts('Did the user select to pay the processing fee'),
    ],
    'mailing_identifier' => [
      'title' => E::ts('Mailing Identifier'),
      'sql_type' => 'varchar(32)',
      'input_type' => 'Text',
      'description' => E::ts('External mailing identifier'),
    ],
    'utm_source' => [
      'title' => E::ts('UTM Source'),
      'sql_type' => 'varchar(128)',
      'input_type' => 'Text',
      'description' => E::ts('UTM Source. This is the original text but is separately broken out into banner etc. We aspire to drop this field but per T354708 Peter Coombe is still reliant on it'),
    ],
    'banner_history_log_id' => [
      'title' => E::ts('Banner History Log ID'),
      'sql_type' => 'varchar(255)',
      'input_type' => 'Text',
      'description' => E::ts('Temporary banner history log ID to associate banner history EventLogging events.'),
    ],
    'screen_width' => [
      'title' => E::ts('Screen Width'),
      'sql_type' => 'int unsigned',
      'input_type' => 'Number',
      'description' => E::ts('Device screen width.'),
    ],
    'screen_height' => [
      'title' => E::ts('Screen Height'),
      'sql_type' => 'int unsigned',
      'input_type' => 'Number',
      'description' => E::ts('Device screen height.'),
    ],
  ],
];

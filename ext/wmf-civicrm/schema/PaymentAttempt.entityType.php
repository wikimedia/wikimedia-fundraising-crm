<?php

use CRM_Wmf_ExtensionUtil as E;

return [
  'name' => 'PaymentAttempt',
  'class' => 'CRM_Wmf_DAO_PaymentAttempt',
  'table' => 'civicrm_payment_attempt',
  'getInfo' => fn() => [
    'title' => E::ts('Payment Attempt'),
    'description' => E::ts('Individual payment attempts from payments-wiki.'),
  ],

  'getFields' => fn() => [

    // ---- Primary key ----
    'id' => [
      'sql_type' => 'int unsigned',
      'required' => TRUE,
      'primary_key' => TRUE,
      'auto_increment' => TRUE,
      'input_type' => 'Number',
      'title' => E::ts('ID'),
    ],

    'ts' => [
      'sql_type' => 'datetime',
      'input_type' => 'Select Date',
      'title' => E::ts('Attempt date'),
    ],
    'contribution_tracking_id' => [
      'sql_type' => 'int unsigned',
      'input_type' => 'Number',
      'title' => E::ts('Contribution tracking ID'),
      'entity_reference' => [
        'entity' => 'ContributionTracking',
        'key' => 'id',
        'fk' => FALSE,
      ],
    ],
    'order_id' => [
      'sql_type' => 'varchar(64)',
      'input_type' => 'Text',
      'title' => E::ts('Order ID (Invoice ID)'),
      'entity_reference' => [
        'entity' => 'Contribution',
        'key' => 'invoice_id',
        'fk' => FALSE,
      ],
    ],
    'gateway' => [
      'sql_type' => 'varchar(32)',
      'input_type' => 'Text',
      'title' => E::ts('Payment gateway'),
    ],
    'payment_method' => [
      'sql_type' => 'varchar(16)',
      'input_type' => 'Text',
      'title' => E::ts('Payment method'),
    ],
    'payment_submethod' => [
      'sql_type' => 'varchar(16)',
      'input_type' => 'Text',
      'title' => E::ts('Payment submethod'),
    ],
    'amount_in_minor_units' => [
      'sql_type' => 'int unsigned',
      'input_type' => 'Number',
      'title' => E::ts('Amount in minor units'),
    ],
    'amount_in_minor_units_repeat' => [
      'sql_type' => 'int unsigned',
      'input_type' => 'Number',
      'title' => E::ts('Amount in minor units repeat count'),
    ],
    'amount_in_minor_units_repeat_fraud' => [
      'sql_type' => 'int unsigned',
      'input_type' => 'Number',
      'title' => E::ts('Amount in minor units fraud repeat count'),
    ],
    'amount_in_minor_units_repeat_decline' => [
      'sql_type' => 'int unsigned',
      'input_type' => 'Number',
      'title' => E::ts('Amount in minor units decline repeat count'),
    ],
    'amount_in_minor_units_repeat_blocked' => [
      'sql_type' => 'int unsigned',
      'input_type' => 'Number',
      'title' => E::ts('Amount in minor units filter block repeat count'),
    ],
    'currency' => [
      'sql_type' => 'varchar(3)',
      'input_type' => 'Text',
      'title' => E::ts('Currency'),
    ],
    'currency_repeat' => [
      'sql_type' => 'int unsigned',
      'input_type' => 'Number',
      'title' => E::ts('Currency repeat count'),
    ],
    'currency_repeat_fraud' => [
      'sql_type' => 'int unsigned',
      'input_type' => 'Number',
      'title' => E::ts('Currency fraud repeat count'),
    ],
    'currency_repeat_decline' => [
      'sql_type' => 'int unsigned',
      'input_type' => 'Number',
      'title' => E::ts('Currency decline repeat count'),
    ],
    'currency_repeat_blocked' => [
      'sql_type' => 'int unsigned',
      'input_type' => 'Number',
      'title' => E::ts('Currency filter block repeat count'),
    ],
    'amount_in_usd_cents' => [
      'sql_type' => 'int unsigned',
      'input_type' => 'Number',
      'title' => E::ts('Amount in USD cents'),
    ],
    'bin_hash' => [
      'sql_type' => 'varchar(32)',
      'input_type' => 'Text',
      'title' => E::ts('Card BIN, hashed'),
    ],
    'bin_hash_repeat' => [
      'sql_type' => 'int unsigned',
      'input_type' => 'Number',
      'title' => E::ts('Card BIN, hashed repeat count'),
    ],
    'bin_hash_repeat_fraud' => [
      'sql_type' => 'int unsigned',
      'input_type' => 'Number',
      'title' => E::ts('Card BIN, hashed fraud repeat count'),
    ],
    'bin_hash_repeat_decline' => [
      'sql_type' => 'int unsigned',
      'input_type' => 'Number',
      'title' => E::ts('Card BIN, hashed decline repeat count'),
    ],
    'bin_hash_repeat_blocked' => [
      'sql_type' => 'int unsigned',
      'input_type' => 'Number',
      'title' => E::ts('Card BIN, hashed filter block repeat count'),
    ],
    'utm_key' => [
      'sql_type' => 'varchar(128)',
      'input_type' => 'Text',
      'title' => E::ts('UTM key'),
    ],
    'utm_key_repeat' => [
      'sql_type' => 'int unsigned',
      'input_type' => 'Number',
      'title' => E::ts('UTM key repeat count'),
    ],
    'utm_key_repeat_fraud' => [
      'sql_type' => 'int unsigned',
      'input_type' => 'Number',
      'title' => E::ts('UTM key fraud repeat count'),
    ],
    'utm_key_repeat_decline' => [
      'sql_type' => 'int unsigned',
      'input_type' => 'Number',
      'title' => E::ts('UTM key decline repeat count'),
    ],
    'utm_key_repeat_blocked' => [
      'sql_type' => 'int unsigned',
      'input_type' => 'Number',
      'title' => E::ts('UTM key filter block repeat count'),
    ],
    'utm_source' => [
      'sql_type' => 'varchar(128)',
      'input_type' => 'Text',
      'title' => E::ts('UTM source'),
    ],
    'utm_source_repeat' => [
      'sql_type' => 'int unsigned',
      'input_type' => 'Number',
      'title' => E::ts('UTM source repeat count'),
    ],
    'utm_source_repeat_fraud' => [
      'sql_type' => 'int unsigned',
      'input_type' => 'Number',
      'title' => E::ts('UTM source fraud repeat count'),
    ],
    'utm_source_repeat_decline' => [
      'sql_type' => 'int unsigned',
      'input_type' => 'Number',
      'title' => E::ts('UTM source decline repeat count'),
    ],
    'utm_source_repeat_blocked' => [
      'sql_type' => 'int unsigned',
      'input_type' => 'Number',
      'title' => E::ts('UTM source filter block repeat count'),
    ],
    'utm_campaign' => [
      'sql_type' => 'varchar(128)',
      'input_type' => 'Text',
      'title' => E::ts('UTM campaign'),
    ],
    'utm_campaign_repeat' => [
      'sql_type' => 'int unsigned',
      'input_type' => 'Number',
      'title' => E::ts('UTM campaign repeat count'),
    ],
    'utm_campaign_repeat_fraud' => [
      'sql_type' => 'int unsigned',
      'input_type' => 'Number',
      'title' => E::ts('UTM campaign fraud repeat count'),
    ],
    'utm_campaign_repeat_decline' => [
      'sql_type' => 'int unsigned',
      'input_type' => 'Number',
      'title' => E::ts('UTM campaign decline repeat count'),
    ],
    'utm_campaign_repeat_blocked' => [
      'sql_type' => 'int unsigned',
      'input_type' => 'Number',
      'title' => E::ts('UTM campaign filter block repeat count'),
    ],
    'utm_medium' => [
      'sql_type' => 'varchar(128)',
      'input_type' => 'Text',
      'title' => E::ts('UTM medium'),
    ],
    'utm_medium_repeat' => [
      'sql_type' => 'int unsigned',
      'input_type' => 'Number',
      'title' => E::ts('UTM medium repeat count'),
    ],
    'utm_medium_repeat_fraud' => [
      'sql_type' => 'int unsigned',
      'input_type' => 'Number',
      'title' => E::ts('UTM medium fraud repeat count'),
    ],
    'utm_medium_repeat_decline' => [
      'sql_type' => 'int unsigned',
      'input_type' => 'Number',
      'title' => E::ts('UTM medium decline repeat count'),
    ],
    'utm_medium_repeat_blocked' => [
      'sql_type' => 'int unsigned',
      'input_type' => 'Number',
      'title' => E::ts('UTM medium filter block repeat count'),
    ],
    'landing_page' => [
      'sql_type' => 'varchar(128)',
      'input_type' => 'Text',
      'title' => E::ts('Landing page'),
    ],
    'email_localpart' => [
      'sql_type' => 'varchar(64)',
      'input_type' => 'Text',
      'title' => E::ts('Local part (username) of email address'),
    ],
    'email_localpart_repeat' => [
      'sql_type' => 'int unsigned',
      'input_type' => 'Number',
      'title' => E::ts('Local part (username) of email address repeat count'),
    ],
    'email_localpart_repeat_fraud' => [
      'sql_type' => 'int unsigned',
      'input_type' => 'Number',
      'title' => E::ts('Local part (username) of email address fraud repeat count'),
    ],
    'email_localpart_repeat_decline' => [
      'sql_type' => 'int unsigned',
      'input_type' => 'Number',
      'title' => E::ts('Local part (username) of email address decline repeat count'),
    ],
    'email_localpart_repeat_blocked' => [
      'sql_type' => 'int unsigned',
      'input_type' => 'Number',
      'title' => E::ts('Local part (username) of email address filter block repeat count'),
    ],
    'email_domain' => [
      'sql_type' => 'varchar(64)',
      'input_type' => 'Text',
      'title' => E::ts('Domain of email address'),
    ],
    'email_domain_repeat' => [
      'sql_type' => 'int unsigned',
      'input_type' => 'Number',
      'title' => E::ts('Domain of email address repeat count'),
    ],
    'email_domain_repeat_fraud' => [
      'sql_type' => 'int unsigned',
      'input_type' => 'Number',
      'title' => E::ts('Domain of email address fraud repeat count'),
    ],
    'email_domain_repeat_decline' => [
      'sql_type' => 'int unsigned',
      'input_type' => 'Number',
      'title' => E::ts('Domain of email address decline repeat count'),
    ],
    'email_domain_repeat_blocked' => [
      'sql_type' => 'int unsigned',
      'input_type' => 'Number',
      'title' => E::ts('Domain of email address filter block repeat count'),
    ],
    'first_name' => [
      'sql_type' => 'varchar(64)',
      'input_type' => 'Text',
      'title' => E::ts('First Name'),
    ],
    'first_name_repeat' => [
      'sql_type' => 'int unsigned',
      'input_type' => 'Number',
      'title' => E::ts('First Name repeat count'),
    ],
    'first_name_repeat_fraud' => [
      'sql_type' => 'int unsigned',
      'input_type' => 'Number',
      'title' => E::ts('First Name fraud repeat count'),
    ],
    'first_name_repeat_decline' => [
      'sql_type' => 'int unsigned',
      'input_type' => 'Number',
      'title' => E::ts('First Name decline repeat count'),
    ],
    'first_name_repeat_blocked' => [
      'sql_type' => 'int unsigned',
      'input_type' => 'Number',
      'title' => E::ts('First Name filter block repeat count'),
    ],
    'last_name' => [
      'sql_type' => 'varchar(64)',
      'input_type' => 'Text',
      'title' => E::ts('Last Name'),
    ],
    'last_name_repeat' => [
      'sql_type' => 'int unsigned',
      'input_type' => 'Number',
      'title' => E::ts('Last Name repeat count'),
    ],
    'last_name_repeat_fraud' => [
      'sql_type' => 'int unsigned',
      'input_type' => 'Number',
      'title' => E::ts('Last Name fraud repeat count'),
    ],
    'last_name_repeat_decline' => [
      'sql_type' => 'int unsigned',
      'input_type' => 'Number',
      'title' => E::ts('Last Name decline repeat count'),
    ],
    'last_name_repeat_blocked' => [
      'sql_type' => 'int unsigned',
      'input_type' => 'Number',
      'title' => E::ts('Last Name filter block repeat count'),
    ],
    'country' => [
      'sql_type' => 'varchar(2)',
      'input_type' => 'Text',
      'title' => E::ts('Country from URL'),
    ],
    'postal_code' => [
      'sql_type' => 'varchar(64)',
      'input_type' => 'Text',
      'title' => E::ts('Postal Code'),
    ],
    'street_address' => [
      'sql_type' => 'varchar(128)',
      'input_type' => 'Text',
      'title' => E::ts('Street address'),
    ],
    'referrer' => [
      'sql_type' => 'varchar(4096)',
      'input_type' => 'Text',
      'title' => E::ts('Referrer'),
    ],
    'screen_height' => [
      'sql_type' => 'int unsigned',
      'input_type' => 'Number',
      'title' => E::ts('Screen height from JS'),
    ],
    'screen_width' => [
      'sql_type' => 'int unsigned',
      'input_type' => 'Number',
      'title' => E::ts('Screen width from JS'),
    ],
    'color_depth' => [
      'sql_type' => 'tinyint',
      'input_type' => 'Number',
      'title' => E::ts('Color depth from JS'),
    ],
    'time_zone_offset' => [
      'sql_type' => 'int',
      'input_type' => 'Number',
      'title' => E::ts('Timezone offset from JS'),
    ],
    'ip_country' => [
      'sql_type' => 'varchar(2)',
      'input_type' => 'Text',
      'title' => E::ts('Country of attempt IP address'),
    ],
    'language' => [
      'sql_type' => 'varchar(12)',
      'input_type' => 'Text',
      'title' => E::ts('Language from URL'),
    ],
    'http_accept_language' => [
      'sql_type' => 'varchar(12)',
      'input_type' => 'Text',
      'title' => E::ts('Language from request header'),
    ],
    'browser' => [
      'sql_type' => 'varchar(255)',
      'input_type' => 'Text',
      'title' => E::ts('Browser'),
    ],
    'browser_version' => [
      'sql_type' => 'varchar(255)',
      'input_type' => 'Text',
      'title' => E::ts('Browser version'),
    ],
    'os' => [
      'sql_type' => 'varchar(255)',
      'input_type' => 'Text',
      'title' => E::ts('OS'),
    ],
    'os_version' => [
      'sql_type' => 'varchar(255)',
      'input_type' => 'Text',
      'title' => E::ts('OS version'),
    ],
    'ja4' => [
      'sql_type' => 'varchar(64)',
      'input_type' => 'Text',
      'title' => E::ts('JA4 TLS'),
    ],
    'ja4h' => [
      'sql_type' => 'varchar(64)',
      'input_type' => 'Text',
      'title' => E::ts('JA4 HTTP'),
    ],
    'user_ip' => [
      'sql_type' => 'varchar(16)',
      'input_type' => 'Text',
      'title' => E::ts('IP address'),
    ],
    'user_ip_repeat' => [
      'sql_type' => 'int unsigned',
      'input_type' => 'Number',
      'title' => E::ts('IP address repeat count'),
    ],
    'user_ip_repeat_fraud' => [
      'sql_type' => 'int unsigned',
      'input_type' => 'Number',
      'title' => E::ts('IP address fraud repeat count'),
    ],
    'user_ip_repeat_decline' => [
      'sql_type' => 'int unsigned',
      'input_type' => 'Number',
      'title' => E::ts('IP address decline repeat count'),
    ],
    'user_ip_repeat_blocked' => [
      'sql_type' => 'int unsigned',
      'input_type' => 'Number',
      'title' => E::ts('IP address filter block repeat count'),
    ],
    'recent_attempt_count' => [
      'sql_type' => 'int unsigned',
      'input_type' => 'Number',
      'title' => E::ts('Count of all recent attempts'),
    ],
    'auth_decline' => [
      'sql_type' => 'boolean',
      'input_type' => 'CheckBox',
      'title' => E::ts('Declined by processor'),
    ],
    'blocked_by_filter' => [
      'sql_type' => 'boolean',
      'input_type' => 'CheckBox',
      'title' => E::ts('Blocked by our filters'),
    ],
    'fraud_flagged_by_processor' => [
      'sql_type' => 'boolean',
      'input_type' => 'CheckBox',
      'title' => E::ts('Flagged as fraudulent by processor'),
    ],
  ],

  'getIndices' => fn() => [
    'index_order_id' => [
      'name' => 'index_order_id',
      'fields' => [
        'order_id' => TRUE,
      ],
      'unique' => TRUE,
    ],
  ],
];


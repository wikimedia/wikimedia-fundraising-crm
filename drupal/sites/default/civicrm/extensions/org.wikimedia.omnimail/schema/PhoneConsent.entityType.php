<?php
use CRM_Omnimail_ExtensionUtil as E;
return [
  'name' => 'PhoneConsent',
  'table' => 'civicrm_phone_consent',
  'class' => 'CRM_Omnimail_DAO_PhoneConsent',
  'getInfo' => fn() => [
    'title' => E::ts('PhoneConsent'),
    'title_plural' => E::ts('PhoneConsents'),
    'description' => E::ts('FIXME'),
    'log' => TRUE,
  ],
  'getFields' => fn() => [
    'id' => [
      'title' => E::ts('ID'),
      'sql_type' => 'int unsigned',
      'input_type' => 'Number',
      'required' => TRUE,
      'description' => E::ts('Unique PhoneConsent ID'),
      'primary_key' => TRUE,
      'auto_increment' => TRUE,
    ],
    'country_code' => [
      'title' => E::ts('Country Code'),
      'sql_type' => 'int unsigned',
      'input_type' => 'Number',
      'default' => 1,
      'description' => E::ts('Country prefix for phone number'),
    ],
    'phone_number' => [
      'title' => E::ts('Phone number'),
      // Ideally this would be numeric - but it joins to a varchar
      // (phone_numeric)
      'sql_type' => 'varchar(32)',
      'input_type' => 'Number',
      'default' => 1,
      'description' => E::ts('Phone number'),
    ],
    'master_recipient_id' => [
      'title' => E::ts('Master recipient ID'),
      'sql_type' => 'bigint unsigned',
      'input_type' => 'Number',
      'default' => 1,
      'description' => E::ts('ID of the recipient that contains consent history'),
    ],
    'consent_date' => [
      'title' => E::ts('Consent date'),
      'sql_type' => 'timestamp',
      'input_type' => NULL,
    ],
    'consent_source' => [
      'title' => E::ts('Consent source'),
      'sql_type' => 'varchar(255)',
      'input_type' => 'Text',
      'description' => E::ts('Type of action'),
    ],
    'opted_in' => [
      'title' => E::ts('Opted in'),
      'sql_type' => 'boolean',
      'input_type' => 'CheckBox',
    ],

  ],
  'getIndices' => fn() => [],
  'getPaths' => fn() => [],
];

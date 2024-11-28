<?php
use CRM_Omnimail_ExtensionUtil as E;
return [
  'name' => 'MailingProviderData',
  'table' => 'civicrm_mailing_provider_data',
  'class' => 'CRM_Omnimail_DAO_MailingProviderData',
  'getInfo' => fn() => [
    'title' => E::ts('Mailing Provider Data'),
    'title_plural' => E::ts('Mailing Provider Data'),
    'description' => E::ts('Data from the mailing provider'),
    'log' => FALSE,
  ],
  'getIndices' => fn() => [
    'contact_identifier' => [
      'fields' => [
        'contact_identifier' => TRUE,
      ],
      'unique' => FALSE,
      'add' => '5.79',
    ],
    'mailing_identifier' => [
      'fields' => [
        'mailing_identifier' => TRUE,
      ],
      'unique' => FALSE,
      'add' => '5.79',
    ],
    'contact_id' => [
      'fields' => [
        'contact_id' => TRUE,
      ],
      'unique' => FALSE,
      'add' => '5.79',
    ],
    'email' => [
      'fields' => [
        'email' => TRUE,
      ],
      'unique' => FALSE,
      'add' => '5.79',
    ],
    'event_type' => [
      'fields' => [
        'event_type' => TRUE,
      ],
      'unique' => FALSE,
      'add' => '5.79',
    ],
    'recipient_action_datetime' => [
      'fields' => [
        'recipient_action_datetime' => TRUE,
      ],
      'unique' => FALSE,
      'add' => '5.79',
    ],
  ],
  'getFields' => fn() => [
    'contact_identifier' => [
      'title' => E::ts('Contact Identifier'),
      'sql_type' => 'varchar(255)',
      'input_type' => 'Text',
      'default' => '',
      'description' => E::ts('External reference for the contact'),
      'primary_key' => TRUE,
    ],
    'mailing_identifier' => [
      'title' => E::ts('Mailing Identifier'),
      'sql_type' => 'varchar(255)',
      'input_type' => 'Text',
      'description' => E::ts('External Reference for the mailing'),
    ],
    'email' => [
      'title' => E::ts('Email'),
      'sql_type' => 'varchar(255)',
      'input_type' => 'Text',
      'description' => E::ts('Email Address'),
    ],
    'recipient_action_datetime' => [
      'title' => E::ts('Recipient Action Datetime'),
      'sql_type' => 'timestamp',
      'input_type' => NULL,
      'description' => E::ts('When the action happened'),
      'primary_key' => TRUE,
    ],
    'event_type' => [
      'title' => E::ts('Event Type'),
      'sql_type' => 'varchar(255)',
      'input_type' => 'Text',
      'default' => '',
      'description' => E::ts('Type of action'),
      'primary_key' => TRUE,
    ],
    'contact_id' => [
      'title' => E::ts('Contact ID'),
      'sql_type' => 'int(16) unsigned',
      'input_type' => 'EntityRef',
      'description' => E::ts('Contact in CiviCRM'),
      'entity_reference' => [
        'entity' => 'Contact',
        'key' => 'id',
      ],
    ],
    'is_civicrm_updated' => [
      'title' => E::ts('Is Civicrm Updated'),
      'sql_type' => 'boolean',
      'input_type' => 'CheckBox',
      'description' => E::ts('Has the action been synchronised through to CiviCRM'),
    ],
  ],
];

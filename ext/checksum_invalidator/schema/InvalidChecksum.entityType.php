<?php
use CRM_ChecksumInvalidator_ExtensionUtil as E;

return [
  'name' => 'InvalidChecksum',
  'table' => 'civicrm_invalid_checksum',
  'class' => 'CRM_ChecksumInvalidator_DAO_InvalidChecksum',
  'getInfo' => fn() => [
    'title' => E::ts('InvalidChecksum'),
    'title_plural' => E::ts('InvalidChecksums'),
    'description' => E::ts('Checksums that been invalidated.'),
    'log' => TRUE,
  ],
  'getFields' => fn() => [
    'id' => [
      'title' => E::ts('ID'),
      'sql_type' => 'int unsigned',
      'input_type' => 'Number',
      'required' => TRUE,
      'description' => E::ts('Unique InvalidChecksum ID'),
      'primary_key' => TRUE,
      'auto_increment' => TRUE,
    ],
    'contact_id' => [
      'title' => E::ts('Contact ID'),
      'sql_type' => 'int unsigned',
      'input_type' => 'EntityRef',
      'required' => TRUE,
      'description' => E::ts('FK to Contact'),
      'entity_reference' => [
        'entity' => 'Contact',
        'key' => 'id',
        'on_delete' => 'CASCADE',
      ],
    ],
    'checksum' => [
      'title' => E::ts('Checksum'),
      'sql_type' => 'varchar(64)',
      'input_type' => 'Text',
      'required' => TRUE,
      'description' => E::ts('Checksum string'),
    ],
    'expiry' => [
      'title' => E::ts('Expiry'),
      'sql_type' => 'datetime',
      'input_type' => 'DateTime',
      'description' => E::ts('Expiry date'),
    ],
  ],
  'getIndices' => fn() => [
    'index_contact_id' => [
      'fields' => [
        'contact_id' => TRUE,
      ],
    ],
    'index_expiry' => [
      'fields' => [
        'expiry' => TRUE,
      ],
    ],
  ],
  'getPaths' => fn() => [],
];

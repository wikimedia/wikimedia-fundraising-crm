<?php
use CRM_Wmf_ExtensionUtil as E;

return [
  [
    'name' => 'CustomGroup_SMS_consent',
    'entity' => 'CustomGroup',
    'cleanup' => 'unused',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'name' => 'SMS_consent',
        'title' => E::ts('SMS consent'),
        'extends' => 'Activity',
        'extends_entity_column_value' => ['182', '183'],
        'weight' => 57,
        'table_name' => 'civicrm_value_sms_consent_52',
        'collapse_adv_display' => TRUE,
      ],
      'match' => ['name'],
    ],
  ],
  [
    'name' => 'OptionGroup_SMS_consent_Consent_source',
    'entity' => 'OptionGroup',
    'cleanup' => 'unused',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'name' => 'SMS_consent_Consent_source',
        'title' => E::ts('SMS consent :: Consent source'),
        'data_type' => 'String',
        'is_reserved' => FALSE,
        'option_value_fields' => ['name', 'label'],
      ],
      'match' => ['name'],
    ],
  ],
  [
    'name' => 'OptionGroup_SMS_consent_Consent_source_OptionValue_Acoustic',
    'entity' => 'OptionValue',
    'cleanup' => 'unused',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'option_group_id.name' => 'SMS_consent_Consent_source',
        'label' => E::ts('Acoustic'),
        'value' => '1',
        'name' => 'Acoustic',
      ],
      'match' => [
        'option_group_id',
        'name',
        'value',
      ],
    ],
  ],
  [
    'name' => 'OptionGroup_SMS_consent_Consent_source_OptionValue_Donation_form',
    'entity' => 'OptionValue',
    'cleanup' => 'unused',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'option_group_id.name' => 'SMS_consent_Consent_source',
        'label' => E::ts('Donation form'),
        'value' => '2',
        'name' => 'Donation_form',
      ],
      'match' => [
        'option_group_id',
        'name',
        'value',
      ],
    ],
  ],
  [
    'name' => 'CustomGroup_SMS_consent_CustomField_Consent_source',
    'entity' => 'CustomField',
    'cleanup' => 'unused',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'custom_group_id.name' => 'SMS_consent',
        'name' => 'Consent_source',
        'label' => E::ts('Consent source'),
        'column_name' => 'consent_source_485',
        'html_type' => 'Select',
        'text_length' => 255,
        'note_columns' => 60,
        'note_rows' => 4,
        'option_group_id.name' => 'SMS_consent_Consent_source',
      ],
      'match' => [
        'name',
        'custom_group_id',
      ],
    ],
  ],
];

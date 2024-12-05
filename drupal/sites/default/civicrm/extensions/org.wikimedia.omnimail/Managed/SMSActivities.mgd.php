<?php

return [
  [
    'name' => 'sms_consent_given',
    'entity' => 'OptionValue',
    'cleanup' => 'unused',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'option_group_id.name' => 'activity_type',
        'label' => 'SMS Consent Given',
        'name' => 'sms_consent_given',
        'grouping' => NULL,
        'filter' => 1,
        'description' => 'Consent given for SMS program',
        'is_optgroup' => FALSE,
        'is_reserved' => TRUE,
        'is_active' => TRUE,
        'value' => 182,
        'component_id' => NULL,
        'domain_id' => NULL,
        'visibility_id' => NULL,
        'icon' => 'fa-mobile-retro',
        'color' => NULL,
      ],
      'match' => [
        'option_group_id',
        'name',
      ],
    ],
  ],
  [
    'name' => 'sms_consent_revoked',
    'entity' => 'OptionValue',
    'cleanup' => 'unused',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'option_group_id.name' => 'activity_type',
        'label' => 'SMS Consent Revoked',
        'name' => 'sms_consent_revoked',
        'grouping' => NULL,
        'filter' => 1,
        'is_default' => FALSE,
        'description' => 'Donor revoked consent for SMS program',
        'is_reserved' => TRUE,
        'value' => 183,
        'is_active' => TRUE,
        'visibility_id' => NULL,
        'icon' => 'fa-bell-slash',
        'color' => NULL,
      ],
      'match' => [
        'option_group_id',
        'name',
      ],
    ],
  ],
];

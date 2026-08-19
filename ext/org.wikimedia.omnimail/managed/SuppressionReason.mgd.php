<?php

return [
  [
    'name' => 'omnimail_suppression_reason',
    'entity' => 'OptionGroup',
    'cleanup' => 'unused',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'title' => 'Suppression Reason',
        'name' => 'suppression_reason',
        'description' => 'Reason a recipient was suppressed by the mailing provider',
        'data_type' => 'Integer',
      ],
      'match' => [
        'name',
      ],
    ],
  ],
  [
    'name' => 'suppression_reason_invalid_system_email_domain',
    'entity' => 'OptionValue',
    'cleanup' => 'unused',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'option_group_id.name' => 'suppression_reason',
        'label' => 'Invalid System Email Domain',
        'value' => 1,
        'name' => 'invalid_system_email_domain',
        'weight' => 1,
        'is_active' => TRUE,
      ],
      'match' => [
        'option_group_id',
        'name',
      ],
    ],
  ],
  [
    'name' => 'suppression_reason_invalid_system_email_local',
    'entity' => 'OptionValue',
    'cleanup' => 'unused',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'option_group_id.name' => 'suppression_reason',
        'label' => 'Invalid System Email Local',
        'value' => 2,
        'name' => 'invalid_system_email_local',
        'weight' => 2,
        'is_active' => TRUE,
      ],
      'match' => [
        'option_group_id',
        'name',
      ],
    ],
  ],
  [
    'name' => 'suppression_reason_global_suppression_list',
    'entity' => 'OptionValue',
    'cleanup' => 'unused',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'option_group_id.name' => 'suppression_reason',
        'label' => 'Global Suppression List',
        'value' => 3,
        'name' => 'global_suppression_list',
        'weight' => 3,
        'is_active' => TRUE,
      ],
      'match' => [
        'option_group_id',
        'name',
      ],
    ],
  ],
  [
    'name' => 'suppression_reason_organization_suppression_list',
    'entity' => 'OptionValue',
    'cleanup' => 'unused',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'option_group_id.name' => 'suppression_reason',
        'label' => 'Organization Suppression List',
        'value' => 4,
        'name' => 'organization_suppression_list',
        'weight' => 4,
        'is_active' => TRUE,
      ],
      'match' => [
        'option_group_id',
        'name',
      ],
    ],
  ],
  [
    'name' => 'suppression_reason_invalid_organization_email_domain',
    'entity' => 'OptionValue',
    'cleanup' => 'unused',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'option_group_id.name' => 'suppression_reason',
        'label' => 'Invalid Organization Email Domain',
        'value' => 5,
        'name' => 'invalid_organization_email_domain',
        'weight' => 5,
        'is_active' => TRUE,
      ],
      'match' => [
        'option_group_id',
        'name',
      ],
    ],
  ],
  [
    'name' => 'suppression_reason_invalid_organization_email_local',
    'entity' => 'OptionValue',
    'cleanup' => 'unused',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'option_group_id.name' => 'suppression_reason',
        'label' => 'Invalid Organization Email Local',
        'value' => 6,
        'name' => 'invalid_organization_email_local',
        'weight' => 6,
        'is_active' => TRUE,
      ],
      'match' => [
        'option_group_id',
        'name',
      ],
    ],
  ],
  [
    'name' => 'suppression_reason_frequency_control',
    'entity' => 'OptionValue',
    'cleanup' => 'unused',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'option_group_id.name' => 'suppression_reason',
        'label' => 'Frequency Control',
        'value' => 7,
        'name' => 'frequency_control',
        'weight' => 7,
        'is_active' => TRUE,
      ],
      'match' => [
        'option_group_id',
        'name',
      ],
    ],
  ],
  [
    'name' => 'suppression_reason_list_level_suppression',
    'entity' => 'OptionValue',
    'cleanup' => 'unused',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'option_group_id.name' => 'suppression_reason',
        'label' => 'List Level Suppression',
        'value' => 8,
        'name' => 'list_level_suppression',
        'weight' => 8,
        'is_active' => TRUE,
      ],
      'match' => [
        'option_group_id',
        'name',
      ],
    ],
  ],
  [
    'name' => 'suppression_reason_query_level_suppression',
    'entity' => 'OptionValue',
    'cleanup' => 'unused',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'option_group_id.name' => 'suppression_reason',
        'label' => 'Query Level Suppression',
        'value' => 9,
        'name' => 'query_level_suppression',
        'weight' => 9,
        'is_active' => TRUE,
      ],
      'match' => [
        'option_group_id',
        'name',
      ],
    ],
  ],
  [
    'name' => 'suppression_reason_mailing_level_suppression',
    'entity' => 'OptionValue',
    'cleanup' => 'unused',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'option_group_id.name' => 'suppression_reason',
        'label' => 'Mailing Level Suppression',
        'value' => 10,
        'name' => 'mailing_level_suppression',
        'weight' => 10,
        'is_active' => TRUE,
      ],
      'match' => [
        'option_group_id',
        'name',
      ],
    ],
  ],
  [
    'name' => 'suppression_reason_duplicate_email_in_nonkey_list',
    'entity' => 'OptionValue',
    'cleanup' => 'unused',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'option_group_id.name' => 'suppression_reason',
        'label' => 'Duplicate Email in Nonkey List',
        'value' => 11,
        'name' => 'duplicate_email_in_nonkey_list',
        'weight' => 11,
        'is_active' => TRUE,
      ],
      'match' => [
        'option_group_id',
        'name',
      ],
    ],
  ],
  [
    'name' => 'suppression_reason_ip_warming',
    'entity' => 'OptionValue',
    'cleanup' => 'unused',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'option_group_id.name' => 'suppression_reason',
        'label' => 'IP Warming',
        'value' => 12,
        'name' => 'ip_warming',
        'weight' => 12,
        'is_active' => TRUE,
      ],
      'match' => [
        'option_group_id',
        'name',
      ],
    ],
  ],
];

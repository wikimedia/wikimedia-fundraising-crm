<?php

return [
  [
    'name' => 'OptionGroup_appeal_20080709183729',
    'entity' => 'OptionGroup',
    'cleanup' => 'unused',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'name' => 'appeal_20080709183729',
        'title' => 'Appeal',
        'is_reserved' => FALSE,
      ],
      'match' => [
        'name',
      ],
    ],
  ],
  [
    'name' => 'OptionGroup_appeal_20080709183729_OptionValue_White_Mail',
    'entity' => 'OptionValue',
    'cleanup' => 'unused',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'option_group_id.name' => 'appeal_20080709183729',
        'label' => 'White Mail',
        'value' => 'White Mail',
        'name' => 'White Mail',
      ],
      'match' => [
        'option_group_id',
        'name',
        'value',
      ],
    ],
  ],
  [
    'name' => 'OptionGroup_appeal_20080709183729_OptionValue_Spontaneous_Donation',
    'entity' => 'OptionValue',
    'cleanup' => 'unused',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'option_group_id.name' => 'appeal_20080709183729',
        'label' => 'spontaneous',
        'value' => 'spontaneous',
        'name' => 'spontaneous',
      ],
      'match' => [
        'option_group_id',
        'name',
        'value',
      ],
    ],
  ],
  [
    'name' => 'OptionGroup_appeal_20080709183729_OptionValue_Facebook',
    'entity' => 'OptionValue',
    'cleanup' => 'unused',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'option_group_id.name' => 'appeal_20080709183729',
        'label' => 'Facebook',
        'value' => 'facebook',
        'name' => 'facebook',
      ],
      'match' => [
        'option_group_id',
        'name',
        'value',
      ],
    ],
  ],
  [
    'name' => 'OptionGroup_appeal_20080709183729_OptionValue_Event',
    'entity' => 'OptionValue',
    'cleanup' => 'unused',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'option_group_id.name' => 'appeal_20080709183729',
        'label' => 'Event',
        'value' => 'event',
        'name' => 'event',
      ],
      'match' => [
        'option_group_id',
        'name',
        'value',
      ],
    ],
  ],
  [
    'name' => 'OptionGroup_appeal_20080709183729_OptionValue_Mobile_Giving',
    'entity' => 'OptionValue',
    'cleanup' => 'unused',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'option_group_id.name' => 'appeal_20080709183729',
        'label' => 'Mobile Giving',
        'value' => 'Mobile Giving',
        'name' => 'Mobile_Giving',
      ],
      'match' => [
        'option_group_id',
        'name',
        'value',
      ],
    ],
  ],
  [
    'name' => 'OptionGroup_appeal_20080709183729_OptionValue_Corp_Matching_Gift',
    'entity' => 'OptionValue',
    'cleanup' => 'unused',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'option_group_id.name' => 'appeal_20080709183729',
        'label' => 'Corp Matching Gift',
        'value' => 'Corp Matching Gift',
        'name' => 'Corp_Matching_Gift',
      ],
      'match' => [
        'option_group_id',
        'name',
        'value',
      ],
    ],
  ],
  [
    'name' => 'CustomGroup_MajorGiftsEngagement',
    'entity' => 'CustomGroup',
    'cleanup' => 'unused',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'name' => 'Major_Gifts_Engagement',
        'title' => 'Major Gifts Engagement',
        'table_name' => 'civicrm_value_mg_engagement',
        'extends' => 'Activity',
        'extends_entity_column_value' => \CRM_Core_PseudoConstant::getKey('CRM_Activity_BAO_Activity', 'activity_type_id', 'Major Gifts Engagement'),
        'collapse_adv_display' => TRUE,
      ],
      'match' => [
        'name',
      ],
    ],
  ],
  [
    'name' => 'CustomGroup_MajorGiftsEngagement_CustomField_ExpectedDonation',
    'entity' => 'CustomField',
    'cleanup' => 'unused',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'custom_group_id.name' => 'Major_Gifts_Engagement',
        'name' => 'Expected_Donation',
        'label' => 'Expected Donation',
        'data_type' => 'Money',
        'html_type' => 'Text',
        'is_searchable' => TRUE,
        'text_length' => 255,
        'note_columns' => 60,
        'note_rows' => 4,
        'column_name' => 'expected_donation',
      ],
      'match' => [
        'name',
        'custom_group_id',
      ],
    ],
  ],
  [
    'name' => 'CustomGroup_MajorGiftsEngagement_CustomField_Appeal',
    'entity' => 'CustomField',
    'cleanup' => 'unused',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'custom_group_id.name' => 'Major_Gifts_Engagement',
        'name' => 'Appeal',
        'label' => 'Appeal',
        'data_type' => 'String',
        'html_type' => 'Select',
        'is_searchable' => FALSE,
        'option_group_id.name' => 'appeal_20080709183729',
        'column_name' => 'appeal',
      ],
      'match' => [
        'name',
        'custom_group_id',
      ],
    ],
  ],
];

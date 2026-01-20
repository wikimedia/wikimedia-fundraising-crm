<?php

return [
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
        'extends_entity_column_value' => \CRM_Core_PseudoConstant::getKey('CRM_Activity_BAO_Activity', 'activity_type_id', 'MajorGiftsEngagement'),
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
];

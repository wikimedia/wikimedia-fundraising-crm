<?php
use CRM_Wmf_ExtensionUtil as E;

return [
  [
    'name' => 'UFGroup_Summary_Overlay_UFField_12',
    'entity' => 'UFField',
    'cleanup' => 'unused',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'match' => ['uf_group_id', 'field_name'],
      'values' => [
        'uf_group_id.name' => 'Summary_Overlay',
        'field_name:name' => 'wmf_donor.lifetime_including_endowment',
        'label' => E::ts('Lifetime USD Total'),
        'field_type' => 'Contact',
      ],
    ],
  ],
  [
    'name' => 'UFGroup_Summary_Overlay_UFField_13',
    'entity' => 'UFField',
    'cleanup' => 'unused',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'match' => ['uf_group_id', 'field_name'],
      'values' => [
        'uf_group_id.name' => 'Summary_Overlay',
        'field_name:name' => 'wmf_donor.all_funds_last_donation_date',
        'label' => E::ts('Last Donated'),
        'field_type' => 'Contact',
      ],
    ],
  ],
  [
    'name' => 'UFGroup_Summary_Overlay_UFField_15',
    'entity' => 'UFField',
    'cleanup' => 'unused',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'match' => ['uf_group_id', 'field_name'],
      'values' => [
        'uf_group_id.name' => 'Summary_Overlay',
        'field_name' => 'id',
        'is_view' => TRUE,
        'label' => E::ts('Contact ID'),
        'field_type' => 'Contact',
      ],
    ],
  ],
];

<?php
use CRM_Wmf_ExtensionUtil as E;

return [
  [
    'name' => 'SavedSearch_Donor_history',
    'entity' => 'SavedSearch',
    'cleanup' => 'unused',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'name' => 'Donor_history',
        'label' => E::ts('Donor History'),
        'api_entity' => 'WMFDonorHistory',
        'api_params' => [
          'version' => 4,
          'select' => [
            'log_date',
            'entity_id',
            'donor_segment_overall:label',
            'donor_status_recur_year:label',
            'donor_status_recur_month:label',
            'donor_status_otg:label',
            'donor_status_overall:label',
            'donor_status_recur_overall:label',
          ],
          'orderBy' => [],
          'where' => [],
          'groupBy' => [],
          'join' => [],
          'having' => [],
        ],
      ],
      'match' => ['name'],
    ],
  ],
  [
    'name' => 'SavedSearch_Donor_history_SearchDisplay_WMF_Donor_History',
    'entity' => 'SearchDisplay',
    'cleanup' => 'unused',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'name' => 'WMF_Donor_History',
        'label' => E::ts('WMF Donor History'),
        'saved_search_id.name' => 'Donor_history',
        'type' => 'table',
        'settings' => [
          'description' => NULL,
          'sort' => [
            ['log_id', 'DESC'],
          ],
          'limit' => 100,
          'pager' => [
            'hide_single' => TRUE,
          ],
          'placeholder' => 5,
          'actions' => FALSE,
          'classes' => ['table', 'table-striped'],
          'columnMode' => 'custom',
          'columns' => [
            [
              'type' => 'field',
              'key' => 'log_date',
              'label' => E::ts('Log Date'),
              'sortable' => TRUE,
            ],
            [
              'type' => 'field',
              'key' => 'donor_segment_overall:label',
              'label' => E::ts('Segment'),
              'sortable' => TRUE,
            ],
            [
              'type' => 'field',
              'key' => 'donor_status_overall:label',
              'label' => E::ts('Statuses: Overall'),
              'sortable' => TRUE,
            ],
            [
              'type' => 'field',
              'key' => 'donor_status_otg:label',
              'label' => E::ts('OTG'),
              'sortable' => TRUE,
            ],
            [
              'type' => 'field',
              'key' => 'donor_status_recur_overall:label',
              'label' => E::ts('Overall Recurring'),
              'sortable' => TRUE,
            ],
            [
              'type' => 'field',
              'key' => 'donor_status_recur_month:label',
              'label' => E::ts('Monthly Recurring'),
              'sortable' => TRUE,
            ],
            [
              'type' => 'field',
              'key' => 'donor_status_recur_year:label',
              'label' => E::ts('Annual Recurring'),
              'sortable' => TRUE,
            ],
          ],
        ],
      ],
      'match' => [
        'saved_search_id',
        'name',
      ],
    ],
  ],
];

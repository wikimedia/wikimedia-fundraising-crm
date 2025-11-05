<?php

use CRM_Wmf_ExtensionUtil as E;

return [
  [
    'name' => 'SavedSearch_Accounting_System_Batches',
    'entity' => 'SavedSearch',
    'cleanup' => 'always',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'name' => 'Accounting_System_Batches',
        'label' => E::ts('Accounting System Batches'),
        'api_entity' => 'Batch',
        'api_params' => [
          'version' => 4,
          'select' => [
            'id',
            'title',
            'batch_data.settled_net_amount',
            'created_date',
            'batch_data.settlement_date',
            'batch_data.settlement_currency',
            'batch_data.settled_donation_amount',
            'batch_data.settled_fee_amount',
            'batch_data.settled_reversal_amount',
            'item_count',
            'status_id:label',
          ],
          'orderBy' => [],
          'where' => [
            [
              'mode_id:name',
              '=',
              'Automatic Batch',
            ],
          ],
          'groupBy' => [],
          'join' => [],
          'having' => [],
        ],
      ],
      'match' => [
        'name',
      ],
    ],
  ],
  [
    'name' => 'SavedSearch_Accounting_System_Batches_SearchDisplay_Accounting_System_Batches',
    'entity' => 'SearchDisplay',
    'cleanup' => 'always',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'name' => 'Accounting_System_Batches',
        'label' => E::ts('Accounting System Batches'),
        'saved_search_id.name' => 'Accounting_System_Batches',
        'type' => 'table',
        'settings' => [
          'description' => E::ts('Batches for export to Intact'),
          'sort' => [
            [
              'batch_data.settlement_date',
              'DESC',
            ],
          ],
          'limit' => 50,
          'pager' => [],
          'placeholder' => 5,
          'columns' => [
            [
              'type' => 'field',
              'key' => 'id',
              'label' => E::ts('Batch ID'),
              'sortable' => TRUE,
            ],
            [
              'type' => 'field',
              'key' => 'title',
              'label' => E::ts('Batch Title'),
              'sortable' => TRUE,
            ],
            [
              'type' => 'field',
              'key' => 'batch_data.settled_net_amount',
              'label' => E::ts('Batch Data: Settled Net Amount'),
              'sortable' => TRUE,
            ],
            [
              'type' => 'field',
              'key' => 'created_date',
              'label' => E::ts('Batch Created Date'),
              'sortable' => TRUE,
            ],
            [
              'type' => 'field',
              'key' => 'batch_data.settlement_date',
              'label' => E::ts('Batch Data: Settlement Date'),
              'sortable' => TRUE,
            ],
            [
              'type' => 'field',
              'key' => 'batch_data.settlement_currency',
              'label' => E::ts('Batch Data: Settlement Currency'),
              'sortable' => TRUE,
            ],
            [
              'type' => 'field',
              'key' => 'batch_data.settled_donation_amount',
              'label' => E::ts('Batch Data: Settled Donation Amount'),
              'sortable' => TRUE,
            ],
            [
              'type' => 'field',
              'key' => 'batch_data.settled_fee_amount',
              'label' => E::ts('Batch Data: Settled Fee Amount'),
              'sortable' => TRUE,
            ],
            [
              'type' => 'field',
              'key' => 'batch_data.settled_reversal_amount',
              'label' => E::ts('Batch Data: Settled Reversal Amount'),
              'sortable' => TRUE,
            ],
            [
              'type' => 'field',
              'key' => 'item_count',
              'label' => E::ts('Batch Number of Items'),
              'sortable' => TRUE,
            ],
            [
              'type' => 'field',
              'key' => 'status_id:label',
              'label' => E::ts('Batch Status'),
              'sortable' => TRUE,
            ],
          ],
          'actions' => TRUE,
          'classes' => [
            'table',
            'table-striped',
          ],
          'actions_display_mode' => 'menu',
        ],
      ],
      'match' => [
        'saved_search_id',
        'name',
      ],
    ],
  ],
];

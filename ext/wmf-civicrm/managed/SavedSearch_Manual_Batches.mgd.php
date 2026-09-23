<?php
use CRM_Wmf_ExtensionUtil as E;

return [
  [
    'name' => 'SavedSearch_Manual_Batches',
    'entity' => 'SavedSearch',
    'cleanup' => 'unused',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'name' => 'Manual_Batches',
        'label' => E::ts('Manual Batches'),
        'api_entity' => 'Batch',
        'api_params' => [
          'version' => 4,
          'select' => [
            'id',
            'title',
            'name',
            'batch_data.settlement_gateway',
            'batch_data.settled_donation_amount',
            'batch_data.settled_fee_amount',
            'batch_data.settled_net_amount',
            'batch_data.settlement_currency',
            'batch_data.settlement_date',
            'batch_data.net_amount_mismatch',
            'item_count',
            'status_id:label',
            'created_date',
          ],
          'orderBy' => [],
          'where' => [
            [
              'mode_id:name',
              '=',
              'Manual Batch',
            ],
          ],
          'groupBy' => [],
          'join' => [],
          'having' => [],
        ],
      ],
      'match' => ['name'],
    ],
  ],
  [
    'name' => 'SavedSearch_Manual_Batches_SearchDisplay_Manual_Batches',
    'entity' => 'SearchDisplay',
    'cleanup' => 'unused',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'name' => 'Manual_Batches',
        'label' => E::ts('Manual Batches'),
        'saved_search_id.name' => 'Manual_Batches',
        'type' => 'table',
        'settings' => [
          'description' => E::ts('Manually entered batches (e.g. ACH/Wire) awaiting confirmation'),
          'sort' => [
            [
              'created_date',
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
              'key' => 'batch_data.settlement_gateway',
              'label' => E::ts('Gateway Account'),
              'sortable' => TRUE,
            ],
            [
              'type' => 'field',
              'key' => 'batch_data.settled_donation_amount',
              'label' => E::ts('Settled Donation Amount'),
              'sortable' => TRUE,
              'editable' => TRUE,
            ],
            [
              'type' => 'field',
              'key' => 'batch_data.settled_fee_amount',
              'label' => E::ts('Settled Fee Amount'),
              'sortable' => TRUE,
              'editable' => TRUE,
            ],
            [
              'type' => 'field',
              'key' => 'batch_data.settled_net_amount',
              'label' => E::ts('Settled Net Amount'),
              'sortable' => TRUE,
              'editable' => TRUE,
            ],
            [
              'type' => 'field',
              'key' => 'batch_data.settlement_currency',
              'label' => E::ts('Settlement Currency'),
              'sortable' => TRUE,
            ],
            [
              'type' => 'field',
              'key' => 'batch_data.settlement_date',
              'label' => E::ts('Settlement Date'),
              'sortable' => TRUE,
            ],
            [
              'type' => 'field',
              'key' => 'item_count',
              'label' => E::ts('Batch Number of Items'),
              'sortable' => TRUE,
              'editable' => TRUE,
            ],
            [
              'type' => 'field',
              'key' => 'status_id:label',
              'label' => E::ts('Batch Status'),
              'sortable' => TRUE,
            ],
            [
              'size' => 'btn-xs',
              'links' => [
                [
                  'path' => '',
                  'icon' => 'fa-check',
                  'text' => E::ts('Confirm'),
                  'style' => 'success',
                  'conditions' => [
                    [
                      'status_id:name',
                      'IN',
                      ['Open'],
                    ],
                    [
                      'batch_data.net_amount_mismatch',
                      '=',
                      FALSE,
                    ],
                  ],
                  'task' => 'confirm',
                  'entity' => 'Batch',
                  'action' => '',
                  'join' => '',
                  'target' => '',
                ],
                [
                  'path' => 'civicrm/contribution/settled#?finance_batch=[name]',
                  'icon' => 'fa-external-link',
                  'text' => E::ts('Search Transactions'),
                  'style' => 'default',
                  'conditions' => [],
                  'task' => '',
                  'entity' => '',
                  'action' => '',
                  'join' => '',
                  'target' => '_blank',
                ],
              ],
              'type' => 'buttons',
              'alignment' => 'text-right',
              'label' => E::ts('View Transactions'),
            ],
          ],
          'actions' => TRUE,
          'classes' => [
            'table',
            'table-striped',
          ],
          'actions_display_mode' => 'menu',
          'columnMode' => 'custom',
          'cssRules' => [
            [
              'bg-warning',
              'status_id:name',
              '=',
              'needs_attention',
            ],
            [
              'bg-success',
              'status_id:name',
              'IN',
              [
                'total_verified',
                'validated',
              ],
            ],
            [
              'bg-info',
              'status_id:name',
              'IN',
              [
                'Open',
              ],
            ],
            [
              'disabled',
              'status_id:name',
              'IN',
              [
                'Closed',
                'Exported',
              ],
            ],
            [
              'bg-danger',
              'batch_data.net_amount_mismatch',
              '=',
              TRUE,
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

<?php
use CRM_Wmf_ExtensionUtil as E;

$batch = 'BatchFinancialBreakdown_Batch_batch_id_01';

return [
  [
    'name' => 'SavedSearch_Finance_break_down',
    'entity' => 'SavedSearch',
    'cleanup' => 'unused',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'name' => 'Finance_break_down',
        'label' => E::ts('Finance break down'),
        'api_entity' => 'BatchFinancialBreakdown',
        'api_params' => [
          'version' => 4,
          'select' => [
            'id',
            'batch_id',
            "$batch.name",
            "$batch.status_id:label",
            "$batch.mode_id:name",
            "$batch.batch_data.settlement_date",
            "$batch.batch_data.settlement_gateway",
            "$batch.batch_data.settlement_gateway_account_id.label",
            "$batch.batch_data.settlement_currency",
            'gl_code',
            'is_endowment',
            'amount',
            'item_count',
          ],
          'orderBy' => [],
          'where' => [],
          'groupBy' => [],
          'join' => [
            ["Batch AS $batch", 'INNER', ['batch_id', '=', "$batch.id"]],
          ],
          'having' => [],
        ],
      ],
      'match' => ['name'],
    ],
  ],
  [
    'name' => 'SavedSearch_Finance_break_down_SearchDisplay_Batch_Financial_Breakdowns_Table_1',
    'entity' => 'SearchDisplay',
    'cleanup' => 'unused',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'name' => 'Batch_Financial_Breakdowns_Table_1',
        'label' => E::ts('Batch Financial Breakdown'),
        'saved_search_id.name' => 'Finance_break_down',
        'type' => 'table',
        'settings' => [
          'description' => E::ts('Amount journalled to each GL code & fund per batch'),
          'sort' => [["$batch.batch_data.settlement_date", 'DESC'], ["$batch.name", 'ASC'], ['gl_code', 'ASC']],
          'limit' => 50,
          'pager' => [],
          'placeholder' => 5,
          'columns' => [
            [
              'type' => 'field',
              'key' => "$batch.name",
              'label' => E::ts('Batch Name'),
              'sortable' => TRUE,
            ],
            ['type' => 'field', 'key' => "$batch.batch_data.settlement_date", 'label' => E::ts('Settlement Date'), 'sortable' => TRUE],
            ['type' => 'field', 'key' => "$batch.batch_data.settlement_gateway", 'label' => E::ts('Gateway'), 'sortable' => TRUE],
            ['type' => 'field', 'key' => "$batch.batch_data.settlement_gateway_account_id.label", 'label' => E::ts('Gateway Account'), 'sortable' => TRUE],
            ['type' => 'field', 'key' => "$batch.status_id:label", 'label' => E::ts('Batch Status'), 'sortable' => TRUE],
            ['type' => 'field', 'key' => 'gl_code', 'label' => E::ts('GL Code'), 'sortable' => TRUE],
            ['type' => 'field', 'key' => 'is_endowment', 'label' => E::ts('Is Endowment'), 'sortable' => TRUE],
            ['type' => 'field', 'key' => "$batch.batch_data.settlement_currency", 'label' => E::ts('Currency'), 'sortable' => TRUE],
            ['type' => 'field', 'key' => 'amount', 'label' => E::ts('Amount'), 'sortable' => TRUE, 'tally' => ['fn' => 'SUM']],
            ['type' => 'field', 'key' => 'item_count', 'label' => E::ts('Item Count'), 'sortable' => TRUE, 'tally' => ['fn' => 'SUM']],
            [
              'type' => 'links',
              'label' => '',
              'links' => [
                [
                  'path' => "civicrm/contribution/settled#?finance_batch=[$batch.name]",
                  'text' => E::ts('Transactions'),
                  'icon' => 'fa-search',
                  'target' => '_blank',
                  'style' => 'default',
                  // Adjustment batches aren't the finance batch of any contribution.
                  'conditions' => [["$batch.mode_id:name", '!=', 'Adjustment Batch']],
                ],
              ],
            ],
          ],
          'actions' => TRUE,
          'classes' => ['table', 'table-striped'],
          'columnMode' => 'custom',
          'actions_display_mode' => 'menu',
          'tally' => ['label' => E::ts('Total'), 'header' => FALSE, 'footer' => TRUE],
          'headerCount' => TRUE,
        ],
      ],
      'match' => ['saved_search_id', 'name'],
    ],
  ],
];

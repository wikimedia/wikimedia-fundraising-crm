<?php
use CRM_Wmf_ExtensionUtil as E;

return [
  [
    'name' => 'SavedSearch_Settled_Transactions',
    'entity' => 'SavedSearch',
    'cleanup' => 'unused',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'name' => 'Settled_Transactions',
        'label' => E::ts('Settled Transactions'),
        'api_entity' => 'Contribution',
        'api_params' => [
          'version' => 4,
          'select' => [
            'finance_batch',
            'contribution_settlement.settlement_batch_reference',
            'settled_total_amount',
            'settled_fee_amount',
            'settled_net_amount',
            'contribution_settlement.settlement_batch_reversal_reference',
            'contribution_settlement.settled_donation_amount',
            'contribution_settlement.settled_fee_reversal_amount',
            'id',
            'contribution_settlement.settled_reversal_amount',
            'contribution_settlement.settled_fee_amount',
            'receive_date',
            'contact_id.sort_name',
            'total_amount',
            'net_amount',
            'fee_amount',
            'financial_type_id:label',
            'contribution_extra.original_currency',
            'contribution_extra.original_amount',
            'contribution_status_id:label',
            'contribution_extra.gateway',
            'contribution_extra.gateway_txn_id',
            'contribution_extra.payment_orchestrator_reconciliation_id',
            'contribution_extra.backend_processor',
            'contribution_extra.backend_processor_txn_id',
            'contribution_extra.source_type',
            'invoice_id',
            'contact_id',
            'contact_id.display_name',
            'segment_Fund:label',
            'Gift_Data.Channel:label',
            'Gift_Data.is_major_gift',
          ],
          'orderBy' => [],
          'where' => [
            ['finance_batch', 'IS NOT EMPTY'],
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
    'name' => 'SavedSearch_Settled_Transactions_SearchDisplay_Settled_Transactions_Table_1',
    'entity' => 'SearchDisplay',
    'cleanup' => 'unused',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'name' => 'Settled_Transactions_Table_1',
        'label' => E::ts('Settled Transactions Display'),
        'saved_search_id.name' => 'Settled_Transactions',
        'type' => 'table',
        'settings' => [
          'description' => E::ts(NULL),
          'sort' => [],
          'limit' => 50,
          'pager' => [],
          'placeholder' => 5,
          'columns' => [
            [
              'type' => 'field',
              'key' => 'finance_batch',
              'label' => E::ts('Finance Settlement Batch'),
              'sortable' => TRUE,
            ],
            [
              'type' => 'field',
              'key' => 'settled_total_amount',
              'label' => E::ts('Settled total amount'),
              'sortable' => TRUE,
              'tally' => [
                'fn' => 'SUM',
              ],
            ],
            [
              'type' => 'field',
              'key' => 'settled_net_amount',
              'label' => E::ts('Settled net amount'),
              'sortable' => TRUE,
              'tally' => [
                'fn' => 'SUM',
              ],
            ],
            [
              'type' => 'field',
              'key' => 'settled_fee_amount',
              'label' => E::ts('Settled fee amount'),
              'sortable' => TRUE,
              'tally' => [
                'fn' => 'SUM',
              ],
            ],
            [
              'type' => 'field',
              'key' => 'id',
              'label' => E::ts('Contribution ID'),
              'sortable' => TRUE,
              'link' => [
                'path' => '',
                'entity' => 'Contribution',
                'action' => 'view',
                'join' => '',
                'target' => 'crm-popup',
                'task' => '',
              ],
              'title' => E::ts('View Contribution'),
              'tally' => [
                'fn' => 'COUNT',
              ],
            ],
            [
              'type' => 'field',
              'key' => 'receive_date',
              'label' => E::ts('Contribution Date'),
              'sortable' => TRUE,
              'tally' => [
                'fn' => NULL,
              ],
            ],
            [
              'type' => 'field',
              'key' => 'contact_id.sort_name',
              'label' => E::ts('Contact Sort Name'),
              'sortable' => TRUE,
              'tally' => [
                'fn' => NULL,
              ],
              'link' => [
                'path' => '',
                'entity' => 'Contact',
                'action' => 'view',
                'join' => 'contact_id',
                'target' => '_blank',
                'task' => '',
              ],
              'title' => E::ts(NULL),
            ],
            [
              'type' => 'field',
              'key' => 'contribution_extra.gateway',
              'label' => E::ts('Gateway'),
              'sortable' => TRUE,
              'tally' => [
                'fn' => NULL,
              ],
            ],
            [
              'type' => 'field',
              'key' => 'contribution_extra.gateway_txn_id',
              'label' => E::ts('Gateway Transaction ID'),
              'sortable' => TRUE,
              'tally' => [
                'fn' => NULL,
              ],
            ],
            [
              'type' => 'field',
              'key' => 'contribution_extra.payment_orchestrator_reconciliation_id',
              'label' => E::ts('Payment Orchestrator Reconciliation ID'),
              'sortable' => TRUE,
              'tally' => [
                'fn' => NULL,
              ],
            ],
            [
              'type' => 'field',
              'key' => 'contribution_extra.backend_processor',
              'label' => E::ts('Backend Processor'),
              'sortable' => TRUE,
              'tally' => [
                'fn' => NULL,
              ],
            ],
            [
              'type' => 'field',
              'key' => 'contribution_extra.backend_processor_txn_id',
              'label' => E::ts('Backend Processor Transaction ID'),
              'sortable' => TRUE,
              'tally' => [
                'fn' => NULL,
              ],
            ],
            [
              'type' => 'field',
              'key' => 'contribution_extra.original_currency',
              'label' => E::ts('Original Currency'),
              'sortable' => TRUE,
              'tally' => [
                'fn' => NULL,
              ],
            ],
            [
              'type' => 'field',
              'key' => 'Gift_Data.Channel:label',
              'label' => E::ts('Gift Data: Channel'),
              'sortable' => TRUE,
            ],
            [
              'type' => 'field',
              'key' => 'Gift_Data.is_major_gift',
              'label' => E::ts('Gift Data: Is Major Gift'),
              'sortable' => TRUE,
            ],
            [
              'type' => 'field',
              'key' => 'contribution_settlement.settlement_batch_reference',
              'label' => E::ts('Batch Reference'),
              'sortable' => TRUE,
              'tally' => [
                'fn' => NULL,
              ],
            ],
            [
              'type' => 'field',
              'key' => 'contribution_settlement.settlement_batch_reversal_reference',
              'label' => E::ts('Batch Reference for Reversal'),
              'sortable' => TRUE,
              'tally' => [
                'fn' => NULL,
              ],
            ],
            [
              'type' => 'field',
              'key' => 'contribution_settlement.settled_donation_amount',
              'label' => E::ts('Settled Donation Amount'),
              'sortable' => TRUE,
              'tally' => [
                'fn' => 'SUM',
              ],
            ],
            [
              'type' => 'field',
              'key' => 'contribution_settlement.settled_fee_amount',
              'label' => E::ts('Settled Fee Amount'),
              'sortable' => TRUE,
              'tally' => [
                'fn' => 'SUM',
              ],
            ],
            [
              'type' => 'field',
              'key' => 'contribution_settlement.settled_reversal_amount',
              'label' => E::ts('Settled Reversal Amount'),
              'sortable' => TRUE,
              'tally' => [
                'fn' => 'SUM',
              ],
            ],
            [
              'type' => 'field',
              'key' => 'contribution_settlement.settled_fee_reversal_amount',
              'label' => E::ts('Settled Fee Reversal Amount'),
              'sortable' => TRUE,
              'tally' => [
                'fn' => 'SUM',
              ],
            ],
            [
              'type' => 'field',
              'key' => 'contribution_status_id:label',
              'label' => E::ts('Contribution Status'),
              'sortable' => TRUE,
              'tally' => [
                'fn' => NULL,
              ],
            ],
            [
              'type' => 'field',
              'key' => 'financial_type_id:label',
              'label' => E::ts('Financial Type'),
              'sortable' => TRUE,
              'tally' => [
                'fn' => NULL,
              ],
            ],
            [
              'type' => 'field',
              'key' => 'contribution_extra.source_type',
              'label' => E::ts('Source Type'),
              'sortable' => TRUE,
              'tally' => [
                'fn' => NULL,
              ],
            ],
            [
              'type' => 'field',
              'key' => 'contribution_extra.original_amount',
              'label' => E::ts('Original Amount Received (unconverted)'),
              'sortable' => TRUE,
              'tally' => [
                'fn' => 'SUM',
              ],
            ],
            [
              'type' => 'field',
              'key' => 'invoice_id',
              'label' => E::ts('Invoice Reference'),
              'sortable' => TRUE,
            ],
            [
              'type' => 'field',
              'key' => 'contact_id',
              'label' => E::ts('Contact ID'),
              'sortable' => TRUE,
            ],
            [
              'type' => 'field',
              'key' => 'contact_id.display_name',
              'label' => E::ts('Contact Display Name'),
              'sortable' => TRUE,
            ],
          ],
          'actions' => TRUE,
          'classes' => ['table', 'table-striped'],
          'actions_display_mode' => 'menu',
          'headerCount' => TRUE,
          'tally' => [
            'label' => E::ts('Total'),
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

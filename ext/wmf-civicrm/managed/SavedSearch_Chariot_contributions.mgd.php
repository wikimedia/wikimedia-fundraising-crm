<?php
use CRM_Wmf_ExtensionUtil as E;

return [
  [
    'name' => 'SavedSearch_Chariot_contributions',
    'entity' => 'SavedSearch',
    'cleanup' => 'always',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'name' => 'Chariot_contributions',
        'label' => E::ts('Chariot contributions'),
        'api_entity' => 'Contribution',
        'api_params' => [
          'version' => 4,
          'select' => [
            'id',
            'receive_date',
            'contact_id.sort_name',
            'total_amount',
            'financial_type_id:label',
            'contribution_status_id:label',
            'GROUP_CONCAT(DISTINCT Contribution_ContributionSoft_contribution_id_01.contact_id.display_name) AS GROUP_CONCAT_Contribution_ContributionSoft_contribution_id_01_contact_id_display_name',
            'GROUP_CONCAT(DISTINCT Contribution_ContributionSoft_contribution_id_02.contact_id.display_name) AS GROUP_CONCAT_Contribution_ContributionSoft_contribution_id_02_contact_id_display_name',
            'GROUP_CONCAT(DISTINCT Contribution_ContributionSoft_contribution_id_03.contact_id.display_name) AS GROUP_CONCAT_Contribution_ContributionSoft_contribution_id_03_contact_id_display_name',
            'contribution_extra.backend_processor',
            'contribution_extra.backend_processor_txn_id',
            'SUBSTRING(contribution_settlement.settlement_batch_reference, 9, 26) AS SUBSTRING_contribution_settlement_settlement_batch_reference_9_26',
            'RIGHT(contribution_extra.gateway_txn_id, 26) AS RIGHT_contribution_extra_gateway_txn_id_26',
          ],
          'orderBy' => [],
          'where' => [
            [
              'contribution_extra.gateway',
              '=',
              'chariot',
            ],
          ],
          'groupBy' => [
            'id',
          ],
          'join' => [
            [
              'ContributionSoft AS Contribution_ContributionSoft_contribution_id_01',
              'LEFT',
              [
                'id',
                '=',
                'Contribution_ContributionSoft_contribution_id_01.contribution_id',
              ],
              [
                'Contribution_ContributionSoft_contribution_id_01.soft_credit_type_id:name',
                '=',
                '"Banking Institution"',
              ],
            ],
            [
              'ContributionSoft AS Contribution_ContributionSoft_contribution_id_02',
              'LEFT',
              [
                'id',
                '=',
                'Contribution_ContributionSoft_contribution_id_02.contribution_id',
              ],
              [
                'Contribution_ContributionSoft_contribution_id_02.soft_credit_type_id:name',
                '=',
                '"donor-advised_fund"',
              ],
            ],
            [
              'ContributionSoft AS Contribution_ContributionSoft_contribution_id_03',
              'LEFT',
              [
                'id',
                '=',
                'Contribution_ContributionSoft_contribution_id_03.contribution_id',
              ],
              [
                'Contribution_ContributionSoft_contribution_id_03.soft_credit_type_id:name',
                '=',
                '"matched_gift"',
              ],
            ],
            [
              'Contact AS Contribution_ContributionSoft_contribution_id_01_ContributionSoft_Contact_contact_id_01',
              'LEFT',
              [
                'Contribution_ContributionSoft_contribution_id_01.contact_id',
                '=',
                'Contribution_ContributionSoft_contribution_id_01_ContributionSoft_Contact_contact_id_01.id',
              ],
            ],
          ],
          'having' => [],
        ],
      ],
      'match' => [
        'name',
      ],
    ],
  ],
  [
    'name' => 'SavedSearch_Chariot_contributions_SearchDisplay_Chariot_contributions',
    'entity' => 'SearchDisplay',
    'cleanup' => 'always',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'name' => 'Chariot_contributions',
        'label' => E::ts('Chariot contributions'),
        'saved_search_id.name' => 'Chariot_contributions',
        'type' => 'table',
        'settings' => [
          'description' => NULL,
          'sort' => [],
          'limit' => 50,
          'pager' => [],
          'placeholder' => 5,
          'columns' => [
            [
              'type' => 'field',
              'key' => 'id',
              'label' => E::ts('ID'),
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
            ],
            [
              'type' => 'field',
              'key' => 'receive_date',
              'label' => E::ts('Date'),
              'sortable' => TRUE,
              'format' => 'dateformatFull',
            ],
            [
              'type' => 'field',
              'key' => 'contact_id.sort_name',
              'label' => E::ts('Donor'),
              'sortable' => TRUE,
              'link' => [
                'path' => '',
                'entity' => 'Contact',
                'action' => 'view',
                'join' => 'contact_id',
                'target' => '',
                'task' => '',
              ],
              'title' => E::ts('View Contact'),
            ],
            [
              'type' => 'field',
              'key' => 'total_amount',
              'label' => E::ts('Total Amount'),
              'sortable' => TRUE,
            ],
            [
              'type' => 'field',
              'key' => 'GROUP_CONCAT_Contribution_ContributionSoft_contribution_id_01_contact_id_display_name',
              'label' => E::ts('Banking institution (SC)'),
              'sortable' => TRUE,
            ],
            [
              'type' => 'field',
              'key' => 'GROUP_CONCAT_Contribution_ContributionSoft_contribution_id_02_contact_id_display_name',
              'label' => E::ts('Holder of DAF'),
              'sortable' => TRUE,
              'link' => [
                'path' => 'civicrm/',
                'entity' => '',
                'action' => '',
                'join' => '',
                'target' => '',
                'task' => '',
              ],
            ],
            [
              'type' => 'field',
              'key' => 'GROUP_CONCAT_Contribution_ContributionSoft_contribution_id_03_contact_id_display_name',
              'label' => E::ts('Matching gift org'),
              'sortable' => TRUE,
            ],
            [
              'type' => 'field',
              'key' => 'contribution_extra.backend_processor',
              'label' => E::ts('Backend Processor'),
              'sortable' => TRUE,
            ],
            [
              'type' => 'field',
              'key' => 'contribution_extra.backend_processor_txn_id',
              'label' => E::ts('Backend Processor Transaction ID'),
              'sortable' => TRUE,
            ],
            [
              'type' => 'field',
              'key' => 'SUBSTRING_contribution_settlement_settlement_batch_reference_9_26',
              'label' => E::ts('deposit'),
              'sortable' => TRUE,
              'link' => [
                'path' => 'https://dashboard.givechariot.com/deposits/deposit_[SUBSTRING_contribution_settlement_settlement_batch_reference_9_26]',
                'entity' => '',
                'action' => '',
                'join' => '',
                'target' => 'crm-popup',
                'task' => '',
              ],
            ],
            [
              'type' => 'field',
              'key' => 'RIGHT_contribution_extra_gateway_txn_id_26',
              'label' => E::ts('Chariot ID'),
              'sortable' => TRUE,
            ],
          ],
          'actions' => TRUE,
          'classes' => [
            'table',
            'table-striped',
          ],
          'columnMode' => 'custom',
          'actions_display_mode' => 'menu',
        ],
      ],
      'match' => [
        'name',
      ],
    ],
  ],
];

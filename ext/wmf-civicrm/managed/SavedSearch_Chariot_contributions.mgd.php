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
            'GROUP_CONCAT(DISTINCT Contribution_ContributionSoft_contribution_id_04.contact_id.display_name) AS GROUP_CONCAT_Contribution_ContributionSoft_contribution_id_04_contact_id_display_name',
            'source',
            'Gift_Data.Channel:label',
            'Gift_Data.Campaign:label',
            'thankyou_date',
            'contribution_extra.no_thank_you',
          ],
          'orderBy' => [],
          'where' => [
            [
              'contribution_extra.gateway',
              'LIKE',
              'chariot%',
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
              'ContributionSoft AS Contribution_ContributionSoft_contribution_id_04',
              'LEFT',
              [
                'id',
                '=',
                'Contribution_ContributionSoft_contribution_id_04.contribution_id',
              ],
              [
                'Contribution_ContributionSoft_contribution_id_04.soft_credit_type_id:name',
                '=',
                '"workplace"',
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
          'sort' => [
            [
              'receive_date',
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
              'title' => 'View Contribution',
              'tally' => [
                'fn' => 'COUNT',
              ],
            ],
            [
              'type' => 'field',
              'key' => 'receive_date',
              'label' => E::ts('Date'),
              'sortable' => TRUE,
              'format' => 'dateformatFull',
              'tally' => [
                'fn' => NULL,
              ],
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
              'title' => 'View Contact',
              'tally' => [
                'fn' => NULL,
              ],
            ],
            [
              'type' => 'field',
              'key' => 'total_amount',
              'label' => 'Total Amount',
              'sortable' => TRUE,
              'tally' => [
                'fn' => 'SUM',
              ],
            ],
            [
              'type' => 'field',
              'key' => 'GROUP_CONCAT_Contribution_ContributionSoft_contribution_id_01_contact_id_display_name',
              'label' => 'Banking institution (SC)',
              'sortable' => TRUE,
              'link' => [
                'path' => 'civicrm/contact/view/contribution?reset=1&action=view&cid=[Contribution_ContributionSoft_contribution_id_01.contact_id]',
                'entity' => '',
                'action' => '',
                'join' => '',
                'target' => '_blank',
                'task' => '',
              ],
              'tally' => [
                'fn' => NULL,
              ],
            ],
            [
              'type' => 'field',
              'key' => 'GROUP_CONCAT_Contribution_ContributionSoft_contribution_id_02_contact_id_display_name',
              'label' => 'Holder of DAF',
              'sortable' => TRUE,
              'link' => [
                'path' => 'civicrm/contact/view?reset=1&action=view&cid=[Contribution_ContributionSoft_contribution_id_02.contact_id]',
                'entity' => '',
                'action' => '',
                'join' => '',
                'target' => '_blank',
                'task' => '',
              ],
              'tally' => [
                'fn' => NULL,
              ],
            ],
            [
              'type' => 'field',
              'key' => 'GROUP_CONCAT_Contribution_ContributionSoft_contribution_id_03_contact_id_display_name',
              'label' => 'Employee',
              'sortable' => TRUE,
              'link' => [
                'path' => 'civicrm/contact/view?reset=1&action=view&cid=[Contribution_ContributionSoft_contribution_id_03.contact_id]',
                'entity' => '',
                'action' => '',
                'join' => '',
                'target' => '_blank',
                'task' => '',
              ],
              'tally' => [
                'fn' => NULL,
              ],
            ],
            [
              'type' => 'field',
              'key' => 'GROUP_CONCAT_Contribution_ContributionSoft_contribution_id_04_contact_id_display_name',
              'label' => 'Matching gift org',
              'sortable' => TRUE,
              'link' => [
                'path' => 'civicrm/contact/view?reset=1&action=view&cid=[Contribution_ContributionSoft_contribution_id_04.contact_id]',
                'entity' => '',
                'action' => '',
                'join' => '',
                'target' => '_blank',
                'task' => '',
              ],
              'tally' => [
                'fn' => NULL,
              ],
            ],
            [
              'type' => 'field',
              'key' => 'contribution_extra.backend_processor',
              'label' => 'Backend Processor',
              'sortable' => TRUE,
              'tally' => [
                'fn' => NULL,
              ],
            ],
            [
              'type' => 'field',
              'key' => 'contribution_extra.backend_processor_txn_id',
              'label' => 'Backend Processor Transaction ID',
              'sortable' => TRUE,
              'tally' => [
                'fn' => NULL,
              ],
            ],
            [
              'type' => 'field',
              'key' => 'SUBSTRING_contribution_settlement_settlement_batch_reference_9_26',
              'label' => 'deposit',
              'sortable' => TRUE,
              'link' => [
                'path' => 'https://dashboard.givechariot.com/deposits/deposit_[SUBSTRING_contribution_settlement_settlement_batch_reference_9_26]',
                'entity' => '',
                'action' => '',
                'join' => '',
                'target' => '_blank',
                'task' => '',
              ],
              'tally' => [
                'fn' => NULL,
              ],
            ],
            [
              'type' => 'field',
              'key' => 'RIGHT_contribution_extra_gateway_txn_id_26',
              'label' => 'Chariot ID',
              'sortable' => TRUE,
              'tally' => [
                'fn' => NULL,
              ],
            ],
            [
              'type' => 'field',
              'key' => 'Gift_Data.Channel:label',
              'label' => 'Gift Data: Channel',
              'sortable' => TRUE,
              'tally' => [
                'fn' => NULL,
              ],
            ],
            [
              'type' => 'field',
              'key' => 'source',
              'label' => 'Contribution Source',
              'sortable' => TRUE,
              'tally' => [
                'fn' => NULL,
              ],
            ],
            [
              'type' => 'field',
              'key' => 'Gift_Data.Campaign:label',
              'label' => 'Gift Data: Gift Type',
              'sortable' => TRUE,
              'tally' => [
                'fn' => NULL,
              ],
            ],
            [
              'type' => 'field',
              'key' => 'thankyou_date',
              'label' => 'Thank-you Date',
              'sortable' => TRUE,
            ],
            [
              'type' => 'field',
              'key' => 'contribution_extra.no_thank_you',
              'label' => 'No Thank-you Reason',
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
          'tally' => [
            'label' => 'Totals',
            'header' => TRUE,
            'footer' => FALSE,
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

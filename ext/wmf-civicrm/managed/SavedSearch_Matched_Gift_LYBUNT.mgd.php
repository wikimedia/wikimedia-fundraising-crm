<?php
use CRM_Wmf_ExtensionUtil as E;

return [
  [
    'name' => 'SavedSearch_Matched_Gift_LYBUNT',
    'entity' => 'SavedSearch',
    'cleanup' => 'unused',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'name' => 'Matched_Gift_LYBUNT',
        'label' => E::ts('Matched gifts: LYBUNT'),
        'description' => E::ts('Cannot be edited via UI without breaking the "Has donation within selected timeframe" filter.'),
        'api_entity' => 'Contact',
        'api_params' => [
          'version' => 4,
          'select' => [
            'sort_name',
            'employer_id.display_name',
            'COUNT(DISTINCT Contact_ContributionSoft_contact_id_01.contribution_id) AS COUNT_Contact_ContributionSoft_contact_id_01_contribution_id',
            'MAX(Contact_ContributionSoft_contact_id_01_ContributionSoft_Contribution_contribution_id_01.receive_date) AS MAX_Contact_ContributionSoft_contact_id_01_contribution_id_receive_date',
            'wmf_donor.donor_segment_overall:label',
            'IF(Contact_Contribution_contact_id_01.id, 1, 0) AS has_contribution_this_year',
            'GROUP_CONCAT(DISTINCT Contact_Contribution_contact_id_01.receive_date ORDER BY Contact_Contribution_contact_id_01.receive_date DESC) AS GROUP_CONCAT_Contact_Contribution_contact',
          ],
          'orderBy' => [],
          'where' => [],
          'groupBy' => ['id'],
          'join' => [
            [
              'ContributionSoft AS Contact_ContributionSoft_contact_id_01',
              'INNER',
              [
                'id',
                '=',
                'Contact_ContributionSoft_contact_id_01.contact_id',
              ],
              [
                'Contact_ContributionSoft_contact_id_01.soft_credit_type_id:name',
                '=',
                '"matched_gift"',
              ],
              // Very slow unless we exclude anonymous
              [
                'Contact_ContributionSoft_contact_id_01.contact_id',
                '<>',
                72,
              ],
            ],
            [
              'Contribution AS Contact_ContributionSoft_contact_id_01_ContributionSoft_Contribution_contribution_id_01',
              'INNER',
              [
                'Contact_ContributionSoft_contact_id_01.contribution_id',
                '=',
                'Contact_ContributionSoft_contact_id_01_ContributionSoft_Contribution_contribution_id_01.id',
              ],
              [
                'Contact_ContributionSoft_contact_id_01_ContributionSoft_Contribution_contribution_id_01.contribution_status_id:name',
                '=',
                '"Completed"',
              ],
            ],
            [
              'ContributionSoft AS Contact_ContributionSoft_contact_id_02',
              'LEFT',
              [
                'id',
                '=',
                'Contact_ContributionSoft_contact_id_02.contact_id',
              ],
              [
                'Contact_ContributionSoft_contact_id_02.soft_credit_type_id:name',
                '=',
                '"matched_gift"',
              ],
            ],
            [
              'Contribution AS Contact_ContributionSoft_contact_id_02_ContributionSoft_Contribution_contribution_id_01',
              'EXCLUDE',
              [
                'Contact_ContributionSoft_contact_id_02.contribution_id',
                '=',
                'Contact_ContributionSoft_contact_id_02_ContributionSoft_Contribution_contribution_id_01.id',
              ],
              [
                'Contact_ContributionSoft_contact_id_02_ContributionSoft_Contribution_contribution_id_01.contribution_status_id:name',
                '=',
                '"Completed"',
              ],
            ],
            [
              'Contribution AS Contact_Contribution_contact_id_01',
              'LEFT',
              [
                'id',
                '=',
                'Contact_Contribution_contact_id_01.contact_id',
              ],
            ],
          ],
          'having' => [],
        ],
      ],
      'match' => ['name'],
    ],
  ],
  [
    'name' => 'SavedSearch_Matched_Gift_LYBUNT_SearchDisplay_Matched_Gift_LYBUNT',
    'entity' => 'SearchDisplay',
    'cleanup' => 'unused',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'name' => 'Matched_Gift_LYBUNT',
        'label' => E::ts('Matched gifts: LYBUNT'),
        'saved_search_id.name' => 'Matched_Gift_LYBUNT',
        'type' => 'table',
        'settings' => [
          'description' => NULL,
          'sort' => [
            [
              'MAX_Contact_ContributionSoft_contact_id_01_contribution_id_receive_date',
              'DESC',
            ],
          ],
          'limit' => 50,
          'pager' => [
            'hide_single' => TRUE,
          ],
          'placeholder' => 5,
          'actions' => TRUE,
          'classes' => ['table', 'table-striped'],
          'columnMode' => 'custom',
          'actions_display_mode' => 'menu',
          'button' => 'Search',
          'headerCount' => TRUE,
          'columns' => [
            [
              'type' => 'field',
              'key' => 'sort_name',
              'label' => E::ts('Name'),
              'sortable' => TRUE,
              'tally' => [
                'fn' => 'COUNT',
              ],
              'link' => [
                'path' => '',
                'entity' => 'Contact',
                'action' => 'view',
                'join' => '',
                'target' => '_blank',
                'task' => '',
              ],
              'title' => E::ts('View Contact'),
            ],
            [
              'type' => 'field',
              'key' => 'employer_id.display_name',
              'label' => E::ts('Employer'),
              'sortable' => TRUE,
              'tally' => [
                'fn' => NULL,
              ],
              'link' => [
                'path' => '',
                'entity' => 'Contact',
                'action' => 'view',
                'join' => 'employer_id',
                'target' => '_blank',
                'task' => '',
              ],
              'title' => E::ts('View Current Employer'),
            ],
            [
              'type' => 'field',
              'key' => 'wmf_donor.donor_segment_overall:label',
              'label' => E::ts('Segment'),
              'sortable' => TRUE,
            ],
            [
              'type' => 'field',
              'key' => 'COUNT_Contact_ContributionSoft_contact_id_01_contribution_id',
              'label' => E::ts('Previously matched'),
              'sortable' => TRUE,
            ],
            [
              'type' => 'field',
              'key' => 'MAX_Contact_ContributionSoft_contact_id_01_contribution_id_receive_date',
              'label' => E::ts('Most recent matched date'),
              'sortable' => TRUE,
              'format' => 'dateformatshortdate',
            ],
            [
              'type' => 'field',
              'key' => 'GROUP_CONCAT_Contact_Contribution_contact',
              'label' => E::ts('Donations within selected timeframe'),
              'sortable' => TRUE,
              'format' => 'dateformatshortdate',
              'link' => [
                'path' => '',
                'entity' => 'Contribution',
                'action' => 'view',
                'join' => 'Contact_Contribution_contact_id_01',
                'target' => 'crm-popup',
                'task' => '',
              ],
              'title' => E::ts('View Contact Contributions'),
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

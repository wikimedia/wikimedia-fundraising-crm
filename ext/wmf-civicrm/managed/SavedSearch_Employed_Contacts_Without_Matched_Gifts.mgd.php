<?php
use CRM_Wmf_ExtensionUtil as E;

return [
  [
    'name' => 'SavedSearch_Employed_contacts_without_matched_gifts',
    'entity' => 'SavedSearch',
    'cleanup' => 'unused',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'name' => 'Employed_contacts_without_matched_gifts',
        'label' => E::ts('Employed contacts without matched gifts'),
        'api_entity' => 'Contact',
        'api_params' => [
          'version' => 4,
          'select' => [
            'sort_name',
            'employer_id.display_name',
            'MAX(Contact_RelationshipCache_Contact_01.relationship_created_date) AS MAX_Contact_RelationshipCache_Contact_01_relationship_created_date',
            'wmf_donor.donor_segment_overall:label',
            'MAX(Contact_ContributionSoft_contact_id_02_ContributionSoft_Contribution_contribution_id_01.receive_date) AS MAX_Contact_ContributionSoft_contact_id_02_ContributionSoft_Contribution_contribution_id_01_receive_date',
            'GROUP_FIRST(Contact_ContributionSoft_contact_id_02_ContributionSoft_Contribution_contribution_id_01.total_amount ORDER BY Contact_ContributionSoft_contact_id_02_ContributionSoft_Contribution_contribution_id_01.receive_date DESC) AS GROUP_FIRST_Contact_ContributionSoft_contact_id_02_ContributionSoft_Contribution_contribution_id_01_total_amount_Contact_ContributionSoft_contact_id_02_ContributionSoft_Contribution_contribution_id_01_receive_date',
            'GROUP_FIRST(Contact_ContributionSoft_contact_id_02_ContributionSoft_Contribution_contribution_id_01.id ORDER BY Contact_ContributionSoft_contact_id_02_ContributionSoft_Contribution_contribution_id_01.receive_date DESC) AS GROUP_FIRST_Contact_ContributionSoft_contact_id_02_ContributionSoft_Contribution_contribution_id_01_id_Contact_ContributionSoft_contact_id_02_ContributionSoft_Contribution_contribution_id_01_receive_date',
          ],
          'orderBy' => [],
          'where' => [],
          'groupBy' => ['id'],
          'join' => [
            [
              'Contact AS Contact_RelationshipCache_Contact_01',
              'INNER',
              'RelationshipCache',
              [
                'id',
                '=',
                'Contact_RelationshipCache_Contact_01.far_contact_id',
              ],
              [
                'Contact_RelationshipCache_Contact_01.near_relation:name',
                '=',
                '"Employer of"',
              ],
              [
                'Contact_RelationshipCache_Contact_01.is_current',
                '=',
                TRUE,
              ],
            ],
            [
              'ContributionSoft AS Contact_ContributionSoft_contact_id_01',
              'LEFT',
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
            ],
            [
              'Contribution AS Contact_ContributionSoft_contact_id_01_ContributionSoft_Contribution_contribution_id_01',
              'EXCLUDE',
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
              'LEFT',
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
          ],
          'having' => [],
        ],
      ],
      'match' => ['name'],
    ],
  ],
  [
    'name' => 'SavedSearch_Employed_contacts_without_matched_gifts_SearchDisplay_Employed_contacts_without_matched_gifts',
    'entity' => 'SearchDisplay',
    'cleanup' => 'unused',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'name' => 'Employed_contacts_without_matched_gifts',
        'label' => E::ts('Employed contacts without matched gifts'),
        'saved_search_id.name' => 'Employed_contacts_without_matched_gifts',
        'type' => 'table',
        'settings' => [
          'description' => NULL,
          'sort' => [
            ['sort_name', 'ASC'],
          ],
          'limit' => 50,
          'pager' => [],
          'placeholder' => 5,
          'actions' => TRUE,
          'classes' => ['table', 'table-striped'],
          'columnMode' => 'custom',
          'actions_display_mode' => 'menu',
          'columns' => [
            [
              'type' => 'field',
              'key' => 'sort_name',
              'label' => E::ts('Name'),
              'sortable' => TRUE,
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
              'key' => 'MAX_Contact_RelationshipCache_Contact_01_relationship_created_date',
              'label' => E::ts('Employer added date'),
              'sortable' => TRUE,
              'format' => 'dateformatshortdate',
            ],
            [
              'type' => 'field',
              'key' => 'wmf_donor.donor_segment_overall:label',
              'label' => E::ts('Segment'),
              'sortable' => TRUE,
            ],
            [
              'type' => 'field',
              'key' => 'MAX_Contact_ContributionSoft_contact_id_02_ContributionSoft_Contribution_contribution_id_01_receive_date',
              'label' => E::ts('Most recent matched gift'),
              'sortable' => TRUE,
              'format' => 'dateformatshortdate',
              'link' => [
                'path' => 'civicrm/contact/view/contribution?reset=1&id=[GROUP_FIRST_Contact_ContributionSoft_contact_id_02_ContributionSoft_Contribution_contribution_id_01_id_Contact_ContributionSoft_contact_id_02_ContributionSoft_Contribution_contribution_id_01_receive_date]&action=view',
                'entity' => '',
                'action' => '',
                'join' => '',
                'target' => 'crm-popup',
                'task' => '',
              ],
            ],
            [
              'type' => 'field',
              'key' => 'GROUP_FIRST_Contact_ContributionSoft_contact_id_02_ContributionSoft_Contribution_contribution_id_01_total_amount_Contact_ContributionSoft_contact_id_02_ContributionSoft_Contribution_contribution_id_01_receive_date',
              'label' => E::ts('Amount'),
              'sortable' => TRUE,
            ],
          ],
          'button' => 'Search',
          'headerCount' => TRUE,
        ],
      ],
      'match' => [
        'saved_search_id',
        'name',
      ],
    ],
  ],
];

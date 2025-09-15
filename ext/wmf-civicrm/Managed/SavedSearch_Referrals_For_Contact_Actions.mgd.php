<?php
use CRM_Wmf_ExtensionUtil as E;

return [
  [
    'name' => 'SavedSearch_Referrals_For_Contact_Actions',
    'entity' => 'SavedSearch',
    'cleanup' => 'unused',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'name' => 'Referrals_For_Contact_Actions',
        'label' => E::ts('Referrals - For Contact Actions'),
        'api_entity' => 'Activity',
        'api_params' => [
          'version' => 4,
          'select' => [
            'subject',
            'Activity_ActivityContact_Contact_02.sort_name',
            'Activity_ActivityContact_Contact_02.email_primary.email',
            'Activity_ActivityContact_Contact_01.sort_name',
            'target_contact_id',
            'source_contact_id',
            'Activity_ActivityContact_Contact_01.email_primary.email',
            'activity_date_time',
          ],
          'orderBy' => [],
          'where' => [
            [
              'activity_type_id:name',
              '=',
              'Contact referral',
            ],
            [
              'Activity_ActivityContact_Contact_01.id',
              '!=',
              'Activity_ActivityContact_Contact_02.id',
              TRUE,
            ],
          ],
          'groupBy' => [],
          'join' => [
            [
              'Contact AS Activity_ActivityContact_Contact_01',
              'LEFT',
              'ActivityContact',
              [
                'id',
                '=',
                'Activity_ActivityContact_Contact_01.activity_id',
              ],
              [
                'Activity_ActivityContact_Contact_01.record_type_id:name',
                '=',
                '"Activity Targets"',
              ],
            ],
            [
              'Contact AS Activity_ActivityContact_Contact_02',
              'LEFT',
              'ActivityContact',
              [
                'id',
                '=',
                'Activity_ActivityContact_Contact_02.activity_id',
              ],
              [
                'Activity_ActivityContact_Contact_02.record_type_id:name',
                '=',
                '"Activity Source"',
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
    'name' => 'SavedSearch_Referrals_For_Contact_Actions_SearchDisplay_Referrals_For_Contact_Actions',
    'entity' => 'SearchDisplay',
    'cleanup' => 'unused',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'name' => 'Referrals_For_Contact_Actions',
        'label' => E::ts('Referrals - For Contact Actions'),
        'saved_search_id.name' => 'Referrals_For_Contact_Actions',
        'type' => 'table',
        'settings' => [
          'sort' => [
            [
              'activity_date_time',
              'DESC',
            ],
          ],
          'limit' => 0,
          'pager' => false,
          'placeholder' => 1,
          'columns' => [
            [
              'links' => [
                [
                  'path' => 'civicrm/contact/merge?reset=1&action=update&oid=[Activity_ActivityContact_Contact_01.id]&cid=[Activity_ActivityContact_Contact_02.id]',
                  'text' => E::ts('Merge'),
                  'style' => 'default',
                  'condition' => [],
                  'task' => '',
                  'entity' => '',
                  'action' => '',
                  'join' => '',
                  'target' => '',
                  'conditions' => [],
                ],
              ],
              'type' => 'links',
              'alignment' => 'text-right',
            ],
            [
              'type' => 'field',
              'key' => 'subject',
              'dataType' => 'String',
              'label' => E::ts('Subject'),
              'sortable' => TRUE,
              'link' => [
                'path' => '',
                'entity' => 'Activity',
                'action' => 'view',
                'join' => '',
                'target' => 'crm-popup',
              ],
              'title' => E::ts('View Activity'),
            ],
            [
              'type' => 'field',
              'key' => 'activity_date_time',
              'dataType' => 'Timestamp',
              'label' => E::ts('Activity Date'),
              'sortable' => TRUE,
            ],
            [
              'type' => 'field',
              'key' => 'target_contact_id',
              'dataType' => 'Array',
              'label' => E::ts('New donor ID'),
              'sortable' => TRUE,
            ],
            [
              'type' => 'field',
              'key' => 'Activity_ActivityContact_Contact_01.sort_name',
              'dataType' => 'String',
              'label' => E::ts('New donor name'),
              'sortable' => TRUE,
              'link' => [
                'path' => '',
                'entity' => 'Contact',
                'action' => 'view',
                'join' => 'Activity_ActivityContact_Contact_01',
                'target' => '_blank',
              ],
              'title' => E::ts('View Activity Contacts'),
            ],
            [
              'type' => 'field',
              'key' => 'Activity_ActivityContact_Contact_01.email_primary.email',
              'dataType' => 'String',
              'label' => E::ts('New donor primary email'),
              'sortable' => TRUE,
            ],
            [
              'type' => 'field',
              'key' => 'Activity_ActivityContact_Contact_02.sort_name',
              'dataType' => 'String',
              'label' => E::ts('Original donor name'),
              'sortable' => TRUE,
              'link' => [
                'path' => '',
                'entity' => 'Contact',
                'action' => 'view',
                'join' => 'Activity_ActivityContact_Contact_02',
                'target' => '_blank',
              ],
              'title' => E::ts('View Activity Contacts 2'),
            ],
            [
              'type' => 'field',
              'key' => 'Activity_ActivityContact_Contact_02.email_primary.email',
              'dataType' => 'String',
              'label' => E::ts('Original donor primary email'),
              'sortable' => TRUE,
            ],
            [
              'type' => 'field',
              'key' => 'source_contact_id',
              'dataType' => 'Array',
              'label' => E::ts('Original donor ID'),
              'sortable' => TRUE,
            ],
          ],
          'actions' => FALSE,
          'classes' => ['table', 'table-striped'],
        ],
      ],
      'match' => [
        'saved_search_id',
        'name',
      ],
    ],
  ],
];

<?php
use CRM_Wmf_ExtensionUtil as E;

return [
  [
    'name' => 'SavedSearch_MG_Stage_History',
    'entity' => 'SavedSearch',
    'cleanup' => 'unused',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'name' => 'MG_Stage_History',
        'label' => E::ts('MG Stage History'),
        'api_entity' => 'Activity',
        'api_params' => [
          'version' => 4,
          'select' => [
            'Activity_ActivityContact_Contact_01.id',
            'MG_Stage.Changed_to:label',
            'activity_date_time',
            'Activity_ActivityContact_Contact_02.display_name',
            'details',
          ],
          'orderBy' => [],
          'where' => [
            [
              'activity_type_id:name',
              '=',
              'MG Stage Change',
            ],
            ['status_id:name', '=', 'Completed'],
          ],
          'groupBy' => [],
          'join' => [
            [
              'Contact AS Activity_ActivityContact_Contact_01',
              'INNER',
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
    'name' => 'SavedSearch_MG_Stage_History_SearchDisplay_MG_Stage_History',
    'entity' => 'SearchDisplay',
    'cleanup' => 'unused',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'name' => 'MG_Stage_History',
        'label' => E::ts('MG Stage History'),
        'saved_search_id.name' => 'MG_Stage_History',
        'type' => 'table',
        'settings' => [
          'description' => NULL,
          'sort' => [
            [
              'activity_date_time',
              'DESC',
            ],
          ],
          'limit' => 0,
          'pager' => FALSE,
          'placeholder' => 3,
          'columns' => [
            [
              'type' => 'field',
              'key' => 'MG_Stage.Changed_to:label',
              'label' => E::ts('Changed to'),
              'sortable' => FALSE,
              'link' => [
                'path' => '',
                'entity' => 'Activity',
                'action' => 'update',
                'join' => '',
                'target' => 'crm-popup',
                'task' => '',
              ],
              'title' => E::ts('Update Activity'),
              'rewrite' => '{if "[MG_Stage.Changed_to:label]"}[MG_Stage.Changed_to:label]{else}None{/if}',
            ],
            [
              'type' => 'field',
              'key' => 'activity_date_time',
              'label' => E::ts('Date'),
              'sortable' => TRUE,
              'format' => 'dateformatshortdate',
            ],
            [
              'type' => 'field',
              'key' => 'Activity_ActivityContact_Contact_02.display_name',
              'label' => E::ts('Changed by'),
              'sortable' => FALSE,
            ],
            [
              'type' => 'html',
              'key' => 'details',
              'label' => E::ts('Reason'),
              'sortable' => FALSE,
            ],
          ],
          'actions' => FALSE,
          'classes' => ['table', 'table-striped'],
          'columnMode' => 'custom',
        ],
      ],
      'match' => [
        'saved_search_id',
        'name',
      ],
    ],
  ],
];

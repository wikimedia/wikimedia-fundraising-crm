<?php
use CRM_Wmf_ExtensionUtil as E;

return [
  [
    'name' => 'SavedSearch_Update_mailing_appeals',
    'entity' => 'SavedSearch',
    'cleanup' => 'unused',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'name' => 'Update_mailing_appeals',
        'label' => E::ts('Update mailing appeals'),
        'api_entity' => 'Mailing',
        'api_params' => [
          'version' => 4,
          'select' => [
            'name',
            'Mailing_Appeal.Appeal:label',
            'scheduled_date',
            'ISNOTNULL(Mailing_Appeal.Appeal) AS has_appeal',
          ],
          'orderBy' => [],
          'where' => [],
          'groupBy' => [],
          'join' => [],
          'having' => [],
        ],
      ],
      'match' => ['name'],
    ],
  ],
  [
    'name' => 'SavedSearch_Update_mailing_appeals_SearchDisplay_Update_mailing_appeals',
    'entity' => 'SearchDisplay',
    'cleanup' => 'unused',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'name' => 'Update_mailing_appeals',
        'label' => E::ts('Update mailing appeals'),
        'saved_search_id.name' => 'Update_mailing_appeals',
        'type' => 'table',
        'settings' => [
          'description' => NULL,
          'sort' => [
            ['scheduled_date', 'DESC'],
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
          'headerCount' => TRUE,
          'toolbar' => [
            [
              'path' => 'civicrm/appeal/add',
              'text' => E::ts('Add Appeal'),
              'target' => 'crm-popup',
              'icon' => 'fa-plus',
              'style' => 'primary',
            ],
          ],
          'columns' => [
            [
              'type' => 'field',
              'key' => 'name',
              'label' => E::ts('Mailing name'),
              'sortable' => TRUE,
            ],
            [
              'type' => 'field',
              'key' => 'Mailing_Appeal.Appeal:label',
              'label' => E::ts('Appeal'),
              'sortable' => TRUE,
              'rewrite' => '',
              'editable' => TRUE,
            ],
            [
              'type' => 'field',
              'key' => 'scheduled_date',
              'label' => E::ts('Scheduled Date'),
              'sortable' => TRUE,
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

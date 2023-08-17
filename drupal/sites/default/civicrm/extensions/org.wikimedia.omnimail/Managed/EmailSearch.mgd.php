<?php

use CRM_Omnimail_ExtensionUtil as E;
return [
  [
    'name' => 'SavedSearch_snooze',
    'entity' => 'SavedSearch',
    'cleanup' => 'always',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'name' => 'snooze',
        'label' => E::ts('snooze'),
        'form_values' => NULL,
        'mapping_id' => NULL,
        'search_custom_id' => NULL,
        'api_entity' => 'Email',
        'api_params' => [
          'version' => 4,
          'select' => [
            'contact_id',
            'contact_id.display_name',
            'email',
            'email_settings.snooze_date',
            'on_hold:label',
            'hold_date',
          ],
          'orderBy' => [],
          'where' => [],
          'groupBy' => [],
          'join' => [],
          'having' => [],
        ],
        'expires_date' => NULL,
        'description' => 'Emails with snooze info',
      ],
      'match' => [
        'name',
      ],
    ],
  ],
  [
    'name' => 'SavedSearch_snooze_SearchDisplay_snooze_table',
    'entity' => 'SearchDisplay',
    'cleanup' => 'always',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'name' => 'snooze_table',
        'label' => E::ts('Emails with snooze data'),
        'saved_search_id.name' => 'snooze',
        'type' => 'table',
        'settings' => [
          'description' => E::ts('Emails with editable snooze date'),
          'sort' => [],
          'limit' => 50,
          'pager' => [],
          'placeholder' => 5,
          'columns' => [
            [
              'type' => 'field',
              'key' => 'email',
              'dataType' => 'String',
              'label' => E::ts('Email'),
              'sortable' => TRUE,
            ],
            [
              'type' => 'field',
              'key' => 'email_settings.snooze_date',
              'dataType' => 'Date',
              'label' => E::ts('Email Settings: Snooze date'),
              'sortable' => TRUE,
              'editable' => TRUE,
            ],
            [
              'type' => 'field',
              'key' => 'contact_id.display_name',
              'dataType' => 'String',
              'label' => E::ts('Contact Display Name'),
              'sortable' => TRUE,
              'link' => [
                'path' => '',
                'entity' => 'Contact',
                'action' => 'view',
                'join' => 'contact_id',
                'target' => '_blank',
              ],
              'title' => E::ts('View Contact'),
            ],
            [
              'type' => 'field',
              'key' => 'contact_id',
              'dataType' => 'Integer',
              'label' => E::ts('Contact ID'),
              'sortable' => TRUE,
            ],
            [
              'links' => [
                [
                  'path' => 'civicrm/a/#/omnimail/remote-contact?cid=[contact_id]',
                  'icon' => 'fa-external-link',
                  'text' => E::ts('Link'),
                  'style' => 'info',
                  'condition' => [],
                  'entity' => '',
                  'action' => '',
                  'join' => '',
                  'target' => '',
                ],
              ],
              'type' => 'links',
              'alignment' => '',
              'label' => E::ts('Acoustic data'),
            ],
          ],
          'actions' => TRUE,
          'classes' => [
            'table',
            'table-striped',
          ],
        ],
        'acl_bypass' => FALSE,
      ],
      'match' => [
        'name',
        'saved_search_id',
      ],
    ],
  ],
];

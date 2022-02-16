<?php

use CRM_Monolog_ExtensionUtil as E;

// This file declares a managed database record of type "ReportTemplate".
// The record will be automatically inserted, updated, or deleted from the
// database as appropriate.
// @see https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_managed/

// Install search display if searchkit is installed.
if (!civicrm_api3('Extension', 'getcount', [
  'full_name' => 'org.civicrm.search_kit',
  'status' => 'installed',
])
 && !civicrm_api3('Extension', 'getcount', [
  // Once we are fully on 5.38 we only need to check search_kit
  // as the renaming will be complete.
  'full_name' => 'org.civicrm.search',
  'status' => 'installed',
])
) {
  return [];
}

return [
  [
    'name' => 'Monolog configuration',
    'entity' => 'SavedSearch',
    'cleanup' => 'never',
    'update' => 'never',
    'params' => [
      'version' => 4,
      'checkPermissions' => FALSE,
      'values' => [
        'name' => 'Monolog configuration',
        'label' => 'Monolog configuration',
        'description' => E::ts('Configured monolog logging handlers'),
        'api_entity' => 'Monolog',
        'api_params' => [
          'version' => 4,
          'select' => [
            'id',
            'name',
            'channel',
            'type:label',
            'description',
            'minimum_severity:label',
            'weight',
            'is_active',
            'is_final',
            'is_default',
          ],
          'orderBy' => ['weight' => 'ASC'],
        ],
      ],
      'chain' => [
        'search_display' => [
          'SearchDisplay',
          'create',
          [
            'values' => [
              'name' => 'Monologs',
              'label' => 'Monolog configuration',
              'saved_search_id' => '$id',
              'type' => 'table',
              'settings' => [
                'limit' => 50,
                'pager' => TRUE,
                'columns' => [
                  [
                    'key' => 'id',
                    'label' => NULL,
                    'dataType' => 'Integer',
                    'type' => 'field',
                  ],
                  [
                    'key' => 'name',
                    'label' => E::ts('Unique name'),
                    'dataType' => 'String',
                    'type' => 'field',
                  ],
                  [
                    'key' => 'channel',
                    'label' => E::ts('Channel'),
                    'dataType' => 'String',
                    'type' => 'field',
                    'editable' => [
                      'entity' => 'Monolog',
                      'options' => FALSE,
                      'serialize' => FALSE,
                      'fk_entity' => NULL,
                      'id' => 'id',
                      'name' => 'channel',
                      'value' => 'channel',
                    ],
                  ],
                  [
                    'key' => 'description',
                    'label' => 'Description',
                    'dataType' => 'String',
                    'type' => 'field',
                    'editable' => [
                      'entity' => 'Monolog',
                      'options' => FALSE,
                      'serialize' => FALSE,
                      'fk_entity' => NULL,
                      'id' => 'id',
                      'name' => 'description',
                      'value' => 'description',
                    ],
                  ],
                  [
                    'key' => 'type:label',
                    'label' => 'Type of log service',
                    'dataType' => 'String',
                    'type' => 'field',
                    'editable' => [
                      'entity' => 'Monolog',
                      'options' => TRUE,
                      'serialize' => FALSE,
                      'fk_entity' => NULL,
                      'id' => 'id',
                      'name' => 'type',
                      'value' => 'type',
                    ],
                  ],
                  [
                    'key' => 'minimum_severity:label',
                    'label' => 'Minimum Severity',
                    'dataType' => 'String',
                    'type' => 'field',
                    'editable' => [
                      'entity' => 'Monolog',
                      'options' => TRUE,
                      'serialize' => FALSE,
                      'fk_entity' => NULL,
                      'id' => 'id',
                      'name' => 'minimum_severity',
                      'value' => 'minimum_severity',
                    ],
                  ],
                  [
                    'key' => 'weight',
                    'label' => 'Weight',
                    'dataType' => 'Integer',
                    'type' => 'field',
                    'editable' => [
                      'entity' => 'Monolog',
                      'options' => FALSE,
                      'serialize' => FALSE,
                      'fk_entity' => NULL,
                      'id' => 'id',
                      'name' => 'weight',
                      'value' => 'weight',
                    ],
                  ],
                  [
                    'key' => 'is_active',
                    'label' => 'Is the handler active',
                    'dataType' => 'Boolean',
                    'type' => 'field',
                    'editable' => [
                      'entity' => 'Monolog',
                      'options' => FALSE,
                      'serialize' => FALSE,
                      'fk_entity' => NULL,
                      'id' => 'id',
                      'name' => 'is_active',
                      'value' => 'is_active',
                    ],
                  ],
                  [
                    'key' => 'is_final',
                    'label' => 'Is this the final handler to apply',
                    'dataType' => 'Boolean',
                    'type' => 'field',
                    'editable' => [
                      'entity' => 'Monolog',
                      'options' => FALSE,
                      'serialize' => FALSE,
                      'fk_entity' => NULL,
                      'id' => 'id',
                      'name' => 'is_final',
                      'value' => 'is_final',
                    ],
                 ],
                  [
                    'key' => 'is_default',
                    'label' => 'Is default log service',
                    'dataType' => 'Boolean',
                    'type' => 'field',
                    'editable' => [
                      'entity' => 'Monolog',
                      'options' => FALSE,
                      'serialize' => FALSE,
                      'fk_entity' => NULL,
                      'id' => 'id',
                      'name' => 'is_default',
                      'value' => 'is_default'
                    ]
                 ],
                ],
                'actions' => TRUE,
              ],
            ],
          ],
        ],
      ],
    ],
  ],
];

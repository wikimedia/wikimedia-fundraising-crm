<?php

use CRM_Monolog_ExtensionUtil as E;

// This file declares a managed SavedSearch and SearchDisplay

return [
  [
    'name' => 'SavedSearch_Monolog_configuration',
    'entity' => 'SavedSearch',
    'cleanup' => 'always',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'name' => 'Monolog configuration',
        'label' => E::ts('Monolog configuration'),
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
          'orderBy' => [
            'is_active' => 'DESC',
            'weight' => 'ASC',
            'is_final' => 'DESC',
          ],
        ],
        'description' => E::ts('Configured monolog logging handlers'),
      ],
      'match' => [
        'name',
      ],
    ],
  ],
  [
    'name' => 'SavedSearch_Monolog_configuration_SearchDisplay_Monolog_configuration_display',
    'entity' => 'SearchDisplay',
    'cleanup' => 'always',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'name' => 'Monolog configuration display',
        'label' => E::ts('Monolog configuration'),
        'saved_search_id.name' => 'Monolog configuration',
        'type' => 'table',
        'settings' => [
          'limit' => 50,
          'classes' => [
            'table',
            'table-striped',
          ],
          'pager' => [
            'show_count' => TRUE,
          ],
          'sort' => [
            [
              'is_active',
              'DESC',
            ],
            [
              'weight',
              'ASC',
            ],
            [
              'is_final',
              'DESC',
            ],
          ],
          'cssRules' => [
            [
              'disabled',
              'is_active',
              '=',
              FALSE,
            ],
          ],
          'columns' => [
            [
              'key' => 'id',
              'label' => E::ts('ID'),
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
              'editable' => TRUE,
            ],
            [
              'key' => 'description',
              'label' => E::ts('Description'),
              'dataType' => 'String',
              'type' => 'field',
              'editable' => TRUE,
            ],
            [
              'key' => 'type:label',
              'label' => E::ts('Type of log service'),
              'dataType' => 'String',
              'type' => 'field',
              'editable' => TRUE,
            ],
            [
              'key' => 'minimum_severity:label',
              'label' => E::ts('Minimum Severity'),
              'dataType' => 'String',
              'type' => 'field',
              'editable' => TRUE,
            ],
            [
              'key' => 'weight',
              'label' => E::ts('Order'),
              'dataType' => 'Integer',
              'type' => 'field',
              'editable' => TRUE,
            ],
            [
              'key' => 'is_active',
              'label' => E::ts('Is the handler active'),
              'dataType' => 'Boolean',
              'type' => 'field',
              'editable' => TRUE,
            ],
            [
              'key' => 'is_final',
              'label' => E::ts('Is this the final handler to apply'),
              'dataType' => 'Boolean',
              'type' => 'field',
              'editable' => TRUE,
              'cssRules' => [
                [
                  'bg-warning',
                  'is_final',
                  '=',
                  TRUE,
                ],
              ],
            ],
            [
              'key' => 'is_default',
              'label' => E::ts('Is default log service'),
              'dataType' => 'Boolean',
              'type' => 'field',
              'editable' => TRUE,
            ],
          ],
          'placeholder' => 5,
        ],
      ],
      'match' => [
        'saved_search_id',
        'name',
      ],
    ],
  ],
];

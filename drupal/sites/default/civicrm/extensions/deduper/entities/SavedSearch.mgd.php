<?php
// SavedSearch + SearchDisplay managed entities.
// Note this works around current limitations of hook_civicrm_managed
// By setting 'update' and 'cleanup' to "never", passing 'version' => 4,
// and using chaining to set the foreign key correctly.
// See https://lab.civicrm.org/dev/report/-/issues/69

// Early return if search_kit is not installed.
if (!civicrm_api3('Extension', 'getcount', [
  'full_name' => 'org.civicrm.search_kit',
  'status' => 'installed',
])) {
  return [];
}
return [
  [
    'name' => 'Contact Name Pairs Search',
    'entity' => 'SavedSearch',
    'cleanup' => 'never',
    'update' => 'never',
    'params' => [
      'version' => 4,
      'checkPermissions' => FALSE,
      'values' => [
        'name' => 'Equivalent_names',
        'label' => 'Equivalent names',
        'api_entity' => 'ContactNamePair',
        'api_params' => [
          'version' => 4,
          'select' => [
            'id',
            'name_a',
            'name_b',
            'is_name_b_nickname',
            'is_name_b_inferior',
          ],
        ],
      ],
      'chain' => [
        'search_display' => [
          'SearchDisplay',
          'create',
          [
            'values' => [
              'name' => 'Equivalent_names',
              'label' => 'Equivalent names',
              'saved_search_id' => '$id',
              'type' => 'table',
              'settings' => [
                'limit' => 50,
                'pager' => TRUE,
                'action' => TRUE,
                'columns' => [
                  [
                    'key' => 'name_a',
                    'label' => 'Name A',
                    'dataType' => 'String',
                    'type' => 'field',
                    'editable' => [
                      'entity' => 'ContactNamePair',
                      'options' => FALSE,
                      'serialize' => FALSE,
                      'fk_entity' => NULL,
                      'id' => 'id',
                      'name' => 'name_a',
                      'value' => 'name_a',
                    ],
                  ],
                  [
                    'key' => 'name_b',
                    'label' => 'Name B',
                    'dataType' => 'String',
                    'type' => 'field',
                    'editable' => [
                      'entity' => 'ContactNamePair',
                      'options' => FALSE,
                      'serialize' => FALSE,
                      'fk_entity' => NULL,
                      'id' => 'id',
                      'name' => 'name_b',
                      'value' => 'name_b',
                    ],
                  ],
                  [
                    'key' => 'is_name_b_nickname',
                    'label' => 'Is Name B a Nickname of Name A?',
                    'dataType' => 'Boolean',
                    'type' => 'field',
                    'editable' => [
                      'entity' => 'ContactNamePair',
                      'options' => FALSE,
                      'serialize' => FALSE,
                      'fk_entity' => NULL,
                      'id' => 'id',
                      'name' => 'is_name_b_nickname',
                      'value' => 'is_name_b_nickname',
                    ],
                  ],
                  [
                    'key' => 'is_name_b_inferior',
                    'label' => 'Is Name B Inferior to Name A?',
                    'dataType' => 'Boolean',
                    'type' => 'field',
                    'editable' => [
                      'entity' => 'ContactNamePair',
                      'options' => FALSE,
                      'serialize' => FALSE,
                      'fk_entity' => NULL,
                      'id' => 'id',
                      'name' => 'is_name_b_inferior',
                      'value' => 'is_name_b_inferior',
                    ],
                  ],
                ],
              ],
              'actions' => TRUE,
              'sort' => [['name_a', 'ASC']],
            ],
          ],
        ],
      ],
    ],
  ],
];

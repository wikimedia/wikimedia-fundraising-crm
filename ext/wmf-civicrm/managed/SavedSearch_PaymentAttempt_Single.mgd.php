<?php
use CRM_Wmf_ExtensionUtil as E;

$select = [];
$columns = [];
foreach (\Civi\Api4\PaymentAttempt::getFields(FALSE)->execute() as $field) {
  $select[] = $field['name'];
  $columns[] = [
    'type' => 'field',
    'key' => $field['name'],
    'label' => $field['label'],
    'forceLabel' => TRUE,
  ];
}

return [
  [
    'name' => 'SavedSearch_PaymentAttempt_Single',
    'entity' => 'SavedSearch',
    'cleanup' => 'unused',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'name' => 'PaymentAttempt_Single',
        'label' => E::ts('Payment Attempt Single'),
        'api_entity' => 'PaymentAttempt',
        'api_params' => [
          'version' => 4,
          'select' => $select,
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
    'name' => 'SavedSearch_PaymentAttempt_Single_SearchDisplay_PaymentAttempt_Single_List',
    'entity' => 'SearchDisplay',
    'cleanup' => 'unused',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'name' => 'PaymentAttempt_Single_List',
        'label' => E::ts('Payment Attempt Single'),
        'saved_search_id.name' => 'PaymentAttempt_Single',
        'type' => 'list',
        'settings' => [
          'description' => NULL,
          'sort' => [
            ['id', 'DESC'],
          ],
          'limit' => 1,
          'pager' => FALSE,
          'placeholder' => 1,
          'style' => 'ul',
          'columns' => $columns,
        ],
      ],
      'match' => [
        'saved_search_id',
        'name',
      ],
    ],
  ],
];

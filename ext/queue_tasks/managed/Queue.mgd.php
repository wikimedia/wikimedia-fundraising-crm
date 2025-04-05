<?php
return [
  [
    'module' => 'queue_tasks',
    'name' => 'batch_merge',
    'entity' => 'Queue',
    'params' => [
      'version' => 4,
      'values' => [
        'name' => 'batch_merge',
        'type' => 'Sql',
        'runner' => 'batch_merge',
        'batch_limit' => 1,
        'retry_limit' => 100,
        'retry_interval' => 130,
        'error' => 'abort',
      ],
    ],
  ],
];

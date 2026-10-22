<?php
// Provides the "Edit gateway identifiers" SearchKit task for Contribution.
return [
  'js' => [
    'ang/wmfSearchTasks.module.js',
    'ang/wmfSearchTasks/*.js',
  ],
  'partials' => [
    'ang/wmfSearchTasks',
  ],
  'requires' => ['crmSearchTasks'],
  'basePages' => [],
];

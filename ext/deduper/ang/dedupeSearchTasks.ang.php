<?php
// Declare Angular module

return [
  'js' => [
    'ang/dedupeSearchTasks.js',
    'ang/dedupeSearchTasks/*.js',
    'ang/dedupeSearchTasks/*/*.js',
  ],
  'partials' => [
    'ang/dedupeSearchTasks',
  ],
  'requires' => ['api4'],
];

<?php
// Declare Angular module

return [
  'bundles' => ['bootstrap3'],
  'js' => [
    'ang/deduper.js',
    'ang/deduper/*.js',
    'ang/deduper/*/*.js',
  ],
  'css' => [
    'ang/deduper.css',
  ],
  'partials' => [
    'ang/deduper',
  ],
  'requires' => [
    'crmUi',
    'crmUtil',
    'ngRoute',
    'contactBasic',
    'conflictBasic',
    'xeditable',
    'angularUtils.directives.dirPagination',
  ],
];

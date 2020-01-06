<?php
// This file declares an Angular module which can be autoloaded
// in CiviCRM. See also:
// http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_angularModules

return [
  'js' =>
    [
      0 => 'ang/deduper.js',
      1 => 'ang/deduper/*.js',
      2 => 'ang/deduper/*/*.js',
    ],
  'css' =>
    [
      0 => 'ang/deduper.css',
    ],
  'partials' =>
    [
      0 => 'ang/deduper',
    ],
  'requires' =>
    [
      0 => 'crmUi',
      1 => 'crmUtil',
      2 => 'ngRoute',
      3 => 'contactBasic',
      4 => 'conflictBasic',
    ],
  'settings' =>
    [
    ],
];

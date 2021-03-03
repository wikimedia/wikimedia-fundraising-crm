<?php
// This file declares an Angular module which can be autoloaded
// in CiviCRM. See also:
// http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_angularModules

return array (
  'js' =>
  array (
    0 => 'ang/forgetme.js',
    1 => 'ang/forgetme/*.js',
    2 => 'ang/forgetme/*/*.js',
  ),
  'css' =>
  array (
    0 => 'ang/forgetme.css',
  ),
  'partials' =>
  array (
    0 => 'ang/forgetme',
  ),
  'settings' => [],
  'requires' => ['ngPrint'],
);

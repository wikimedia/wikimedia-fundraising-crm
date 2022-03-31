<?php
// This file declares an Angular module which can be autoloaded
// in CiviCRM. See also:
// \https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_angularModules/n
return [
  'js' => [
    'ang/omnimail.js',
    'ang/omnimail/*.js',
    'ang/omnimail/*/*.js',
  ],
  'css' => [
    'ang/omnimail.css',
  ],
  'partials' => [
    'ang/omnimail',
  ],
  'requires' => [
    'crmUi',
    'crmUtil',
    'ngRoute',
  ],
  'settings' => [],
];

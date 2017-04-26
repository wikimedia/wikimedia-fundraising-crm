<?php

define( 'DRUPAL_ROOT', realpath( __DIR__ ) . "/../../drupal" );
require_once( DRUPAL_ROOT . "/sites/all/modules/wmf_common/tests/includes/BaseWmfDrupalPhpUnitTestCase.php" );
require_once( DRUPAL_ROOT . "/sites/all/modules/offline2civicrm/tests/includes/BaseChecksFileTest.php" );

// Argh.  Crib from _drush_bootstrap_drupal_site_validate
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';

chdir( DRUPAL_ROOT );
require_once( "includes/bootstrap.inc" );
drupal_bootstrap( DRUPAL_BOOTSTRAP_FULL );

// Drupal just usurped PHPUnit's error handler.  Kick it off the throne.
restore_error_handler();

// Load contrib libs so tests can inherit from them.
require_once( DRUPAL_ROOT . '/../vendor/autoload.php' );

putenv('CIVICRM_SETTINGS=' . DRUPAL_ROOT . '/sites/default/civicrm.settings.php');
require_once DRUPAL_ROOT . '/sites/default/civicrm/extensions/org.wikimedia.omnimail/tests/phpunit/bootstrap.php';


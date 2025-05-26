<?php

$templateDir = tempnam(sys_get_temp_dir(), 'crmunit');
unlink($templateDir);
mkdir($templateDir);
define('CIVICRM_TEMPLATE_COMPILEDIR', $templateDir);
define('WMF_CRM_PHPUNIT', TRUE);
define('CIVICRM_TEST', TRUE);
define('DRUPAL_ROOT', __DIR__ . "/drupal");
require_once(__DIR__ . "/vendor/mrmarkfrench/silverpop-php-connector/test/tests/BaseTestClass.php");
require_once(__DIR__ . "/vendor/mrmarkfrench/silverpop-php-connector/test/tests/SilverpopBaseTestClass.php");

// Argh.  Crib from _drush_bootstrap_drupal_site_validate
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';

chdir(__DIR__ . '/drupal');
require_once("includes/bootstrap.inc");
drupal_bootstrap(DRUPAL_BOOTSTRAP_FULL);

// Drupal just usurped PHPUnit's error handler.  Kick it off the throne.
restore_error_handler();

// Load contrib libs so tests can inherit from them.
require_once(__DIR__ . '/vendor/autoload.php');

putenv('CIVICRM_SETTINGS=' . DRUPAL_ROOT . '/sites/default/civicrm.settings.php');
require_once __DIR__ . '/ext/wmf-civicrm/tests/phpunit/bootstrap.php';
civicrm_initialize();
// This causes errors to be thrown rather than the user-oriented html being presented on a fatal error.
// Note that the CRM_Core_TemporaryErrorScope reverts the scope on _deconstruct so
// the scope lasts until the variable is unset (by the script finishing)
// or some other process alters it (which it shouldn't). Even though the $errorScope variable
// is unused it needs to be set or the _deconstruct will take place instantly.
$errorScope = \CRM_Core_TemporaryErrorScope::useException();
// Uncomment this if you would like to see all of the
// watchdog messages when a test fails. Can be useful
// to debug tests in CI where you can't see the syslog.
/*
if (!defined('PRINT_WATCHDOG_ON_TEST_FAIL')) {
  define('PRINT_WATCHDOG_ON_TEST_FAIL', TRUE);
}
*/

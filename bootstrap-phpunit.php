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

if (file_exists(__DIR__ . "/drupal/sites/default/settings.php")) {
  chdir(__DIR__ . '/drupal');
  require_once("includes/bootstrap.inc");
  drupal_bootstrap(DRUPAL_BOOTSTRAP_FULL);
  // Drupal just usurped PHPUnit's error handler.  Kick it off the throne.
  restore_error_handler();
}

// Load contrib libs so tests can inherit from them.
require_once(__DIR__ . '/vendor/autoload.php');

if (file_exists(__DIR__ . '/private/civicrm.settings.php')) {
  putenv('CIVICRM_SETTINGS=' . __DIR__ . '/private/civicrm.settings.php');
}
else {
  putenv('CIVICRM_SETTINGS=' . DRUPAL_ROOT . '/sites/default/civicrm.settings.php');
}
ini_set('memory_limit', '2G');

eval(cv('php:boot --level=classloader', 'phpcode'));
if (function_exists('civicrm_initialize')) {
  // Still required for Drupal it seems.
  civicrm_initialize();
}
$baseDirs = (array) glob(__DIR__ . '/ext/*/tests/phpunit');
$baseDirs[] = __DIR__ . '/ext/wmf-civicrm';

foreach ($baseDirs as $directory) {
  $loader = new \Composer\Autoload\ClassLoader();
  $loader->add('CRM_', $directory);
  $loader->add('Civi\\', $directory);
  $loader->add('api_', $directory);
  $loader->add('api\\', $directory);
  $loader->register();
}

require_once getenv('CIVICRM_SETTINGS');

/**
 * Call the "cv" command.
 *
 * @param string $cmd
 *   The rest of the command to send.
 * @param string $decode
 *   Ex: 'json' or 'phpcode'.
 * @return mixed
 *   Response output (if the command executed normally).
 *   For 'raw' or 'phpcode', this will be a string. For 'json', it could be any JSON value.
 * @throws \RuntimeException
 *   If the command terminates abnormally.
 */
function cv(string $cmd, string $decode = 'json') {
  $cmd = 'cv ' . $cmd;
  $descriptorSpec = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => STDERR];
  $oldOutput = getenv('CV_OUTPUT');
  putenv('CV_OUTPUT=json');

  // Execute `cv` in the original folder. This is a work-around for
  // phpunit/codeception, which seem to manipulate PWD.
  $cmd = sprintf('cd %s; %s', escapeshellarg(getenv('PWD')), $cmd);

  $process = proc_open($cmd, $descriptorSpec, $pipes, __DIR__);
  putenv("CV_OUTPUT=$oldOutput");
  fclose($pipes[0]);
  $result = stream_get_contents($pipes[1]);
  fclose($pipes[1]);
  if (proc_close($process) !== 0) {
    throw new RuntimeException("Command failed ($cmd):\n$result");
  }
  switch ($decode) {
    case 'raw':
      return $result;

    case 'phpcode':
      // If the last output is /*PHPCODE*/, then we managed to complete execution.
      if (substr(trim($result), 0, 12) !== '/*BEGINPHP*/' || substr(trim($result), -10) !== '/*ENDPHP*/') {
        throw new \RuntimeException("Command failed ($cmd):\n$result");
      }
      return $result;

    case 'json':
      return json_decode($result, 1);

    default:
      throw new RuntimeException("Bad decoder format ($decode)");
  }
}

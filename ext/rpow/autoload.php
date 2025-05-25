<?php

// Load global functions
require_once __DIR__ . '/rpow.php';

// Allow PEAR DB to find our DB driver class
// NOTE: The civix templates will also register this post-boot, but we need it pre-boot. So it goes.
set_include_path(__DIR__ . PATH_SEPARATOR . get_include_path());

/**
 * Define an autoloader for Rpow.
 *
 * Rpow uses the namespace 'CRM_Rpow', but we need to be able
 * to use it very early (pre-boot). The admin will have to
 * add this classloader to the `civicrm.settings.php`.
 */
function rpow_autoload($class) {
  $prefix = 'CRM_Rpow_';
  $base_dir = __DIR__ . '/CRM/Rpow/';
  $len = strlen($prefix);
  if (strncmp($prefix, $class, $len) !== 0) {
    return;
  }
  $relative_class = substr($class, $len);
  $file = $base_dir . str_replace('_', '/', $relative_class) . '.php';
  if (file_exists($file)) {
    require $file;
  }
}
spl_autoload_register('rpow_autoload');

<?php

require_once 'import_extensions.civix.php';
// phpcs:disable
use CRM_ImportExtensions_ExtensionUtil as E;
// phpcs:enable

/**
 * Implements hook_civicrm_config().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_config/
 */
function import_extensions_civicrm_config(&$config): void {
  _import_extensions_civix_civicrm_config($config);
}

/**
 * Implements hook_civicrm_install().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_install
 */
function import_extensions_civicrm_install(): void {
  _import_extensions_civix_civicrm_install();
}

/**
 * Implements hook_civicrm_enable().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_enable
 */
function import_extensions_civicrm_enable(): void {
  _import_extensions_civix_civicrm_enable();
}

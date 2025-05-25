<?php

require_once 'standalonemigrate.civix.php';

use CRM_Standalonemigrate_ExtensionUtil as E;

/**
 * Implements hook_civicrm_config().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_config/
 */
function standalonemigrate_civicrm_config(&$config): void {
  _standalonemigrate_civix_civicrm_config($config);
}

/**
 * Implements hook_civicrm_install().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_install
 */
function standalonemigrate_civicrm_install(): void {
  _standalonemigrate_civix_civicrm_install();
}

/**
 * Implements hook_civicrm_enable().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_enable
 */
function standalonemigrate_civicrm_enable(): void {
  _standalonemigrate_civix_civicrm_enable();
}

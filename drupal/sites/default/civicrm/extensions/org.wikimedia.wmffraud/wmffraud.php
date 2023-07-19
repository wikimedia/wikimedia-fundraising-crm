<?php

require_once 'wmffraud.civix.php';
use CRM_Wmffraud_ExtensionUtil as E;
use SmashPig\Tests\TestingGlobalConfiguration;
use SmashPig\Tests\TestingContext;

/**
 * Implements hook_civicrm_config().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_config
 */
function wmffraud_civicrm_config(&$config) {
  _wmffraud_civix_civicrm_config($config);
}

/**
 * Implements hook_civicrm_install().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_install
 */
function wmffraud_civicrm_install() {
  _wmffraud_civix_civicrm_install();
}

function wmffraud_civicrm_testSetup(): void {
  // Initialize SmashPig with a fake context object
  $config = TestingGlobalConfiguration::create();
  TestingContext::init($config);
}

/**
 * Implements hook_civicrm_enable().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_enable
 */
function wmffraud_civicrm_enable() {
  _wmffraud_civix_civicrm_enable();
}

// --- Functions below this ship commented out. Uncomment as required. ---

/**
 * Implements hook_civicrm_preProcess().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_preProcess
 *

 // */

/**
 * Implements hook_civicrm_navigationMenu().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_navigationMenu
 *
function wmffraud_civicrm_navigationMenu(&$menu) {
  _wmffraud_civix_insert_navigation_menu($menu, NULL, array(
    'label' => E::ts('The Page'),
    'name' => 'the_page',
    'url' => 'civicrm/the-page',
    'permission' => 'access CiviReport,access CiviContribute',
    'operator' => 'OR',
    'separator' => 0,
  ));
  _wmffraud_civix_navigationMenu($menu);
} // */

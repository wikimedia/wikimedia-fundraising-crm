<?php

require_once 'smashpig.civix.php';

use CRM_SmashPig_ExtensionUtil as E;

/**
 * Implements hook_civicrm_config().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_config
 */
function smashpig_civicrm_config(&$config) {
  _smashpig_civix_civicrm_config($config);
}

/**
 * Implements hook_civicrm_install().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_install
 */
function smashpig_civicrm_install() {
  _smashpig_civix_civicrm_install();
}

/**
 * Implements hook_civicrm_postInstall().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_postInstall
 */
function smashpig_civicrm_postInstall() {
  _smashpig_civix_civicrm_postInstall();
}

/**
 * Implements hook_civicrm_uninstall().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_uninstall
 */
function smashpig_civicrm_uninstall() {
  _smashpig_civix_civicrm_uninstall();
}

/**
 * Implements hook_civicrm_enable().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_enable
 */
function smashpig_civicrm_enable() {
  _smashpig_civix_civicrm_enable();
}

/**
 * Implements hook_civicrm_disable().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_disable
 */
function smashpig_civicrm_disable() {
  _smashpig_civix_civicrm_disable();
}

/**
 * Implements hook_civicrm_upgrade().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_upgrade
 */
function smashpig_civicrm_upgrade($op, CRM_Queue_Queue $queue = NULL) {
  return _smashpig_civix_civicrm_upgrade($op, $queue);
}

/**
 * Implements hook_civicrm_entityTypes().
 *
 * Declare entity types provided by this module.
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_entityTypes
 */
function smashpig_civicrm_entityTypes(&$entityTypes) {
  _smashpig_civix_civicrm_entityTypes($entityTypes);
}

// --- Functions below this ship commented out. Uncomment as required. ---

/**
 * Implements hook_civicrm_preProcess().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_preProcess
 *
 * function smashpig_civicrm_preProcess($formName, &$form) {
 *
 * } // */

/**
 * Implements hook_civicrm_navigationMenu().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_navigationMenu
 */
function smashpig_civicrm_navigationMenu(&$menu) {
  _smashpig_civix_insert_navigation_menu($menu, 'Administer/System Settings', [
    'label' => E::ts('SmashPig Settings'),
    'name' => 'SmashPig Settings',
    'url' => 'civicrm/settings/smashpig',
    'permission' => 'administer CiviCRM',
    'operator' => 'AND',
    'separator' => 0,
    'active' => 1,
  ]);
  _smashpig_civix_navigationMenu($menu);
}

function smashpig_civicrm_links($op, $objectName, $objectId, &$links, &$mask, &$values) {
  //create a Send Failure Notification link for a given recurring contribution
  if ($objectName === 'Contribution' && $op === 'contribution.selector.recurring') {
    $links[] = [
      'name' => ts('Send Failure Notification'),
      'title' => ts('Send Failure Notification'),
      'url' => 'civicrm/smashpig/notification?type=recurringfailure',
      'qs' => "contribution_recur_id=$objectId&entity_id=$objectId",
      'class' => 'crm-popup large-popup',
    ];
  }
}

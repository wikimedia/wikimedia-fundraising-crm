<?php

require_once 'damaged.civix.php';
// phpcs:disable
use CRM_Damaged_ExtensionUtil as E;
// phpcs:enable

/**
 * Implements hook_civicrm_config().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_config/
 */
function damaged_civicrm_config(&$config) {
  _damaged_civix_civicrm_config($config);
}

/**
 * Implements hook_civicrm_install().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_install
 */
function damaged_civicrm_install() {
  _damaged_civix_civicrm_install();
}

/**
 * Implements hook_civicrm_postInstall().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_postInstall
 */
function damaged_civicrm_postInstall() {
  _damaged_civix_civicrm_postInstall();
}

/**
 * Implements hook_civicrm_uninstall().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_uninstall
 */
function damaged_civicrm_uninstall() {
  _damaged_civix_civicrm_uninstall();
}

/**
 * Implements hook_civicrm_enable().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_enable
 */
function damaged_civicrm_enable() {
  _damaged_civix_civicrm_enable();
}

/**
 * Implements hook_civicrm_disable().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_disable
 */
function damaged_civicrm_disable() {
  _damaged_civix_civicrm_disable();
}

/**
 * Implements hook_civicrm_upgrade().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_upgrade
 */
function damaged_civicrm_upgrade($op, CRM_Queue_Queue $queue = NULL) {
  return _damaged_civix_civicrm_upgrade($op, $queue);
}

/**
 * Implements hook_civicrm_entityTypes().
 *
 * Declare entity types provided by this module.
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_entityTypes
 */
function damaged_civicrm_entityTypes(&$entityTypes) {
  _damaged_civix_civicrm_entityTypes($entityTypes);
}

// --- Functions below this ship commented out. Uncomment as required. ---

/**
 * Implements hook_civicrm_preProcess().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_preProcess
 */
//function damaged_civicrm_preProcess($formName, &$form) {
//
//}

/**
 * Implements hook_civicrm_navigationMenu().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_navigationMenu
 */
//function damaged_civicrm_navigationMenu(&$menu) {
//  _damaged_civix_insert_navigation_menu($menu, 'Mailings', [
//    'label' => E::ts('New subliminal message'),
//    'name' => 'mailing_subliminal_message',
//    'url' => 'civicrm/mailing/subliminal',
//    'permission' => 'access CiviMail',
//    'operator' => 'OR',
//    'separator' => 0,
//  ]);
//  _damaged_civix_navigationMenu($menu);
//}

/* Implements hook_civicrm_searchKitTasks().
*
* @param array[] $tasks
*
* @noinspection PhpUnused
*/
function damaged_civicrm_searchKitTasks(array &$tasks) {
  $tasks['Damaged']['resend'] = [
    'title' => E::ts('Resend %1', [1 => 'damaged']),
    'icon' => 'fa-trash',
    'apiBatch' => [
      'action' => 'resendToQueue',
      'params' => NULL, 
      'confirmMsg' => E::ts('Are you sure you want to resend %1 damaged message to the queue?'), 
      'runMsg' => E::ts('Resending %1 Damaged message to its original queue...'),
      'successMsg' => E::ts('Successfully resent %1 Damaged message to its original queue.'),
      'errorMsg' => E::ts('An error occurred while attempting to resend %1 Damaged message to their original queue.'),
    ],
  ];
}
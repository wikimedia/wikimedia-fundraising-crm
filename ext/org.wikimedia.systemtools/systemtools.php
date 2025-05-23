<?php

require_once 'systemtools.civix.php';
use CRM_Systemtools_ExtensionUtil as E;

/**
 * Implements hook_civicrm_config().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_config/
 */
function systemtools_civicrm_config(&$config) {
  _systemtools_civix_civicrm_config($config);
  $listener = function(\Civi\Core\Event\QueryEvent $e) {
    global $user;
    $uid = is_a($user, 'stdClass') ? (int) $user->uid : 'unknown';
    $e->query = '/* User : ' . $uid . ' */' . $e->query;
  };
  Civi::dispatcher()->addListener('civi.db.query', $listener);
}

/**
 * Implements hook_civicrm_install().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_install
 */
function systemtools_civicrm_install() {
  _systemtools_civix_civicrm_install();
}

/**
 * Implements hook_civicrm_enable().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_enable
 */
function systemtools_civicrm_enable() {
  _systemtools_civix_civicrm_enable();
}

// --- Functions below this ship commented out. Uncomment as required. ---

/**
 * Implements hook_civicrm_preProcess().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_preProcess
 *

 // */

/**
 * Implements hook_civicrm_navigationMenu().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_navigationMenu
 *
function systemtools_civicrm_navigationMenu(&$menu) {
  _systemtools_civix_insert_navigation_menu($menu, 'Mailings', array(
    'label' => E::ts('New subliminal message'),
    'name' => 'mailing_subliminal_message',
    'url' => 'civicrm/mailing/subliminal',
    'permission' => 'access CiviMail',
    'operator' => 'OR',
    'separator' => 0,
  ));
  _systemtools_civix_navigationMenu($menu);
} // */

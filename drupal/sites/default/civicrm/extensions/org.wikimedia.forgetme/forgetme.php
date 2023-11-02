<?php

require_once 'forgetme.civix.php';
use CRM_Forgetme_ExtensionUtil as E;

/**
 * Implements hook_civicrm_config().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_config
 */
function forgetme_civicrm_config(&$config) {
  _forgetme_civix_civicrm_config($config);
}

/**
 * Implements hook_civicrm_install().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_install
 */
function forgetme_civicrm_install() {
  _forgetme_civix_civicrm_install();
}

/**
 * Implements hook_civicrm_enable().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_enable
 */
function forgetme_civicrm_enable() {
  _forgetme_civix_civicrm_enable();
}

/**
 * Implements hook_civicrm_angularModules().
 *
 * Generate a list of Angular modules.
 *
 * Note: This hook only runs in CiviCRM 4.5+. It may
 * use features only available in v4.6+.
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_angularModules
 */
function forgetme_civicrm_angularModules(&$angularModules) {
  $angularModules['ngPrint'] = [
    'js' => ['bower_components/ngPrint/ngPrint.js'],
    'css' => ['bower_components/ngPrint/ngPrint.css'],
    'ext' => 'org.wikimedia.forgetme',
  ];

}

/**
 * Implements hook_alterLogTables().
 *
 * @param array $logTableSpec
 */
function forgetme_civicrm_alterLogTables(&$logTableSpec) {
  $staticDataTables = ['civicrm_deleted_email'];
  foreach ($staticDataTables as $staticDataTable) {
    if (isset($logTableSpec[$staticDataTable])) {
      unset($logTableSpec[$staticDataTable]);
    }
  }
}

/**
 * Add forgetme action.
 */
function forgetme_civicrm_summaryActions(&$actions, $contactID) {
  $actions['contact_forgetme'] = array(
    'title' => E::ts('Forget Me'),
    'ref' => 'contact-forgetme',
    'key' => 'contact-forgetme',
    'weight' => 0,
    'class' => 'no-popup',
    'href' => str_replace('^', '#', CRM_Utils_System::url('civicrm/a/^/forgetme/forget/' . (int) $contactID)),
    'permissions' => array('edit all contacts')
  );
}

/**
 * Implements hook_alterAPIPermissions().
 *
 * @param string $entity
 * @param string $action
 * @param array $params
 * @param array $permissions
 */
function forgetme_civicrm_alterAPIPermissions($entity, $action, &$params, &$permissions) {
  $permissions['default']['showme'] = ['view all contacts'];
  $permissions['default']['forgetme'] = ['edit all contacts'];
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
function forgetme_civicrm_navigationMenu(&$menu) {
  _forgetme_civix_insert_navigation_menu($menu, 'Mailings', array(
    'label' => E::ts('New subliminal message'),
    'name' => 'mailing_subliminal_message',
    'url' => 'civicrm/mailing/subliminal',
    'permission' => 'access CiviMail',
    'operator' => 'OR',
    'separator' => 0,
  ));
  _forgetme_civix_navigationMenu($menu);
} // */

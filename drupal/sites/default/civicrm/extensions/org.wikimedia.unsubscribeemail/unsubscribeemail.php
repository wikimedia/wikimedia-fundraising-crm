<?php

require_once 'unsubscribeemail.civix.php';

/**
 * Implements hook_civicrm_config().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_config
 */
function unsubscribeemail_civicrm_config(&$config) {
  _unsubscribeemail_civix_civicrm_config($config);
}

/**
 * Implements hook_civicrm_xmlMenu().
 *
 * @param array $files
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_xmlMenu
 */
function unsubscribeemail_civicrm_xmlMenu(&$files) {
  _unsubscribeemail_civix_civicrm_xmlMenu($files);
}

/**
 * Implements hook_civicrm_install().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_install
 */
function unsubscribeemail_civicrm_install() {
  _unsubscribeemail_civix_civicrm_install();
}

/**
 * Implements hook_civicrm_uninstall().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_uninstall
 */
function unsubscribeemail_civicrm_uninstall() {
  _unsubscribeemail_civix_civicrm_uninstall();
}

/**
 * Implements hook_civicrm_enable().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_enable
 */
function unsubscribeemail_civicrm_enable() {
  _unsubscribeemail_civix_civicrm_enable();
}

/**
 * Implements hook_civicrm_disable().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_disable
 */
function unsubscribeemail_civicrm_disable() {
  _unsubscribeemail_civix_civicrm_disable();
}

/**
 * Implements hook_civicrm_upgrade().
 *
 * @param $op string, the type of operation being performed; 'check' or 'enqueue'
 * @param $queue CRM_Queue_Queue, (for 'enqueue') the modifiable list of pending up upgrade tasks
 *
 * @return mixed
 *   Based on op. for 'check', returns array(boolean) (TRUE if upgrades are pending)
 *                for 'enqueue', returns void
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_upgrade
 */
function unsubscribeemail_civicrm_upgrade($op, CRM_Queue_Queue $queue = NULL) {
  return _unsubscribeemail_civix_civicrm_upgrade($op, $queue);
}

/**
 * Implements hook_civicrm_managed().
 *
 * Generate a list of entities to create/deactivate/delete when this module
 * is installed, disabled, uninstalled.
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_managed
 */
function unsubscribeemail_civicrm_managed(&$entities) {
  _unsubscribeemail_civix_civicrm_managed($entities);
}

/**
 * Implements hook_civicrm_caseTypes().
 *
 * Generate a list of case-types.
 *
 * @param array $caseTypes
 *
 * Note: This hook only runs in CiviCRM 4.4+.
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_caseTypes
 */
function unsubscribeemail_civicrm_caseTypes(&$caseTypes) {
  _unsubscribeemail_civix_civicrm_caseTypes($caseTypes);
}

/**
 * Implements hook_civicrm_angularModules().
 *
 * Generate a list of Angular modules.
 *
 * Note: This hook only runs in CiviCRM 4.5+. It may
 * use features only available in v4.6+.
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_caseTypes
 */
function unsubscribeemail_civicrm_angularModules(&$angularModules) {
_unsubscribeemail_civix_civicrm_angularModules($angularModules);
}

/**
 * Implements hook_civicrm_alterSettingsFolders().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_alterSettingsFolders
 */
function unsubscribeemail_civicrm_alterSettingsFolders(&$metaDataFolders = NULL) {
  _unsubscribeemail_civix_civicrm_alterSettingsFolders($metaDataFolders);
}

/**
 * Implements hook_civicrm_navigationMenu().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_navigationMenu
 */
function unsubscribeemail_civicrm_navigationMenu(&$menu) {
  _unsubscribeemail_civix_insert_navigation_menu($menu, NULL, array(
    'label' => ts('Unsubscribe email', array('domain' => 'org.wikimedia.unsubscribeemail')),
    'name' => 'unsubscribe_email',
    'url' => 'civicrm/a/#/email/unsubscribe',
    'permission' => 'access CiviCRM',
    'parentID' => civicrm_api3('Navigation', 'getvalue', array('name' => 'Contacts', 'return' => 'id', 'options' => array('limit' => 1))),
  ));
  _unsubscribeemail_civix_navigationMenu($menu);
}

/**
 * Functions below this ship commented out. Uncomment as required.
 *

/**
 * Implements hook_civicrm_preProcess().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_preProcess
 *
function unsubscribeemail_civicrm_preProcess($formName, &$form) {

} // */


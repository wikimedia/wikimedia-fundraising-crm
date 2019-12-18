<?php

require_once 'sendannualtyemail.civix.php';
use CRM_Sendannualtyemail_ExtensionUtil as E;

/**
 * Implements hook_civicrm_config().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_config
 */
function sendannualtyemail_civicrm_config(&$config) {
  _sendannualtyemail_civix_civicrm_config($config);
}

/**
 * Implements hook_civicrm_xmlMenu().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_xmlMenu
 */
function sendannualtyemail_civicrm_xmlMenu(&$files) {
  _sendannualtyemail_civix_civicrm_xmlMenu($files);
}

/**
 * Implements hook_civicrm_install().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_install
 */
function sendannualtyemail_civicrm_install() {
  _sendannualtyemail_civix_civicrm_install();
}

/**
 * Implements hook_civicrm_postInstall().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_postInstall
 */
function sendannualtyemail_civicrm_postInstall() {
  _sendannualtyemail_civix_civicrm_postInstall();
}

/**
 * Implements hook_civicrm_uninstall().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_uninstall
 */
function sendannualtyemail_civicrm_uninstall() {
  _sendannualtyemail_civix_civicrm_uninstall();
}

/**
 * Implements hook_civicrm_enable().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_enable
 */
function sendannualtyemail_civicrm_enable() {
  _sendannualtyemail_civix_civicrm_enable();
}

/**
 * Implements hook_civicrm_disable().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_disable
 */
function sendannualtyemail_civicrm_disable() {
  _sendannualtyemail_civix_civicrm_disable();
}

/**
 * Implements hook_civicrm_upgrade().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_upgrade
 */
function sendannualtyemail_civicrm_upgrade($op, CRM_Queue_Queue $queue = NULL) {
  return _sendannualtyemail_civix_civicrm_upgrade($op, $queue);
}

/**
 * Implements hook_civicrm_managed().
 *
 * Generate a list of entities to create/deactivate/delete when this module
 * is installed, disabled, uninstalled.
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_managed
 */
function sendannualtyemail_civicrm_managed(&$entities) {
  _sendannualtyemail_civix_civicrm_managed($entities);
}

/**
 * Implements hook_civicrm_caseTypes().
 *
 * Generate a list of case-types.
 *
 * Note: This hook only runs in CiviCRM 4.4+.
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_caseTypes
 */
function sendannualtyemail_civicrm_caseTypes(&$caseTypes) {
  _sendannualtyemail_civix_civicrm_caseTypes($caseTypes);
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
function sendannualtyemail_civicrm_angularModules(&$angularModules) {
  _sendannualtyemail_civix_civicrm_angularModules($angularModules);
}

/**
 * Implements hook_civicrm_alterSettingsFolders().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_alterSettingsFolders
 */
function sendannualtyemail_civicrm_alterSettingsFolders(&$metaDataFolders = NULL) {
  _sendannualtyemail_civix_civicrm_alterSettingsFolders($metaDataFolders);
}

/**
 * Implements hook_civicrm_entityTypes().
 *
 * Declare entity types provided by this module.
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_entityTypes
 */
function sendannualtyemail_civicrm_entityTypes(&$entityTypes) {
  _sendannualtyemail_civix_civicrm_entityTypes($entityTypes);
}

/**
 * Add dedupe searches to actions available.
 *
 * @param array $actions
 * @param int $contactID
 */
function sendannualtyemail_civicrm_summaryActions(&$actions, $contactID) {
  if ($contactID === NULL) {
    return;
  }
  try {
    $weight = 510;
    $actions['otherActions']['sendannutaltyemail'] = [
      'title' => ts('Send Annual Thank You letter'),
      'name' => ts('Send Annual Thank You letter'),
      'weight' => $weight,
      'ref' => 'crm-contact_actions-list',
      'key' => 'sendannutaltyemail',
      'class' => 'crm-popup small-popup',
      'href' =>  CRM_Utils_System::url('civicrm/send-annual-ty-email', array(
        'reset' => 1
      )),
    ];
  } catch (CiviCRM_API3_Exception $e) {
    // This would most likely happen if viewing a deleted contact since we are not forcing
    // them to be returned. Keep calm & carry on.
  }
}

// --- Functions below this ship commented out. Uncomment as required. ---

/**
 * Implements hook_civicrm_preProcess().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_preProcess
 *
function sendannualtyemail_civicrm_preProcess($formName, &$form) {

} // */

/**
 * Implements hook_civicrm_navigationMenu().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_navigationMenu
 *
function sendannualtyemail_civicrm_navigationMenu(&$menu) {
  _sendannualtyemail_civix_insert_navigation_menu($menu, 'Mailings', array(
    'label' => E::ts('New subliminal message'),
    'name' => 'mailing_subliminal_message',
    'url' => 'civicrm/mailing/subliminal',
    'permission' => 'access CiviMail',
    'operator' => 'OR',
    'separator' => 0,
  ));
  _sendannualtyemail_civix_navigationMenu($menu);
} // */

<?php

require_once 'wmf_thankyou.civix.php';
use CRM_WmfThankyou_ExtensionUtil as E;

/**
 * Implements hook_civicrm_config().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_config/
 */
function wmf_thankyou_civicrm_config(&$config) {
  _wmf_thankyou_civix_civicrm_config($config);
}

/**
 * Implements hook_civicrm_xmlMenu().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_xmlMenu
 */
function wmf_thankyou_civicrm_xmlMenu(&$files) {
  _wmf_thankyou_civix_civicrm_xmlMenu($files);
}

/**
 * Implements hook_civicrm_install().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_install
 */
function wmf_thankyou_civicrm_install() {
  _wmf_thankyou_civix_civicrm_install();
}

/**
 * Implements hook_civicrm_postInstall().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_postInstall
 */
function wmf_thankyou_civicrm_postInstall() {
  _wmf_thankyou_civix_civicrm_postInstall();
}

/**
 * Implements hook_civicrm_uninstall().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_uninstall
 */
function wmf_thankyou_civicrm_uninstall() {
  _wmf_thankyou_civix_civicrm_uninstall();
}

/**
 * Implements hook_civicrm_enable().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_enable
 */
function wmf_thankyou_civicrm_enable() {
  _wmf_thankyou_civix_civicrm_enable();
}

/**
 * Implements hook_civicrm_disable().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_disable
 */
function wmf_thankyou_civicrm_disable() {
  _wmf_thankyou_civix_civicrm_disable();
}

/**
 * Implements hook_civicrm_upgrade().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_upgrade
 */
function wmf_thankyou_civicrm_upgrade($op, CRM_Queue_Queue $queue = NULL) {
  return _wmf_thankyou_civix_civicrm_upgrade($op, $queue);
}

/**
 * Implements hook_civicrm_managed().
 *
 * Generate a list of entities to create/deactivate/delete when this module
 * is installed, disabled, uninstalled.
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_managed
 */
function wmf_thankyou_civicrm_managed(&$entities) {
  _wmf_thankyou_civix_civicrm_managed($entities);
}

/**
 * Implements hook_civicrm_caseTypes().
 *
 * Generate a list of case-types.
 *
 * Note: This hook only runs in CiviCRM 4.4+.
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_caseTypes
 */
function wmf_thankyou_civicrm_caseTypes(&$caseTypes) {
  _wmf_thankyou_civix_civicrm_caseTypes($caseTypes);
}

/**
 * Implements hook_civicrm_angularModules().
 *
 * Generate a list of Angular modules.
 *
 * Note: This hook only runs in CiviCRM 4.5+. It may
 * use features only available in v4.6+.
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_angularModules
 */
function wmf_thankyou_civicrm_angularModules(&$angularModules) {
  _wmf_thankyou_civix_civicrm_angularModules($angularModules);
}

/**
 * Implements hook_civicrm_alterSettingsFolders().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_alterSettingsFolders
 */
function wmf_thankyou_civicrm_alterSettingsFolders(&$metaDataFolders = NULL) {
  _wmf_thankyou_civix_civicrm_alterSettingsFolders($metaDataFolders);
}

/**
 * Implements hook_civicrm_entityTypes().
 *
 * Declare entity types provided by this module.
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_entityTypes
 */
function wmf_thankyou_civicrm_entityTypes(&$entityTypes) {
  _wmf_thankyou_civix_civicrm_entityTypes($entityTypes);
}

/**
 * Implements hook_civicrm_thems().
 */
function wmf_thankyou_civicrm_themes(&$themes) {
  _wmf_thankyou_civix_civicrm_themes($themes);
}

function wmf_thankyou_civicrm_links($op, $objectName, $objectId, &$links, &$mask, &$values) {
  //create a Send Invoice link with the context of the participant's order ID (a custom participant field)
  if ($objectName === 'Contribution' && $op === 'contribution.selector.row') {
    $links[] = [
      'name' => ts('Send Thank You'),
      'title' => ts('Send Thank You'),
      'url' => 'civicrm/wmf_thankyou',
      'qs' => "contribution_id=$objectId",
      'class' => 'crm-popup small-popup',
    ];
  }
}

/**
 * Add send summary actions email to available actions.
 *
 * @param array $actions
 * @param int $contactID
 */
function wmf_thankyou_civicrm_summaryActions(&$actions, $contactID) {
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
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_preProcess
 *
function wmf_thankyou_civicrm_preProcess($formName, &$form) {

} // */

/**
 * Implements hook_civicrm_navigationMenu().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_navigationMenu
 *
function wmf_thankyou_civicrm_navigationMenu(&$menu) {
  _wmf_thankyou_civix_insert_navigation_menu($menu, 'Mailings', array(
    'label' => E::ts('New subliminal message'),
    'name' => 'mailing_subliminal_message',
    'url' => 'civicrm/mailing/subliminal',
    'permission' => 'access CiviMail',
    'operator' => 'OR',
    'separator' => 0,
  ));
  _wmf_thankyou_civix_navigationMenu($menu);
} // */

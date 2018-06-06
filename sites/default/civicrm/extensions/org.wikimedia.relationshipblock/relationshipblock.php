<?php

require_once 'relationshipblock.civix.php';
use CRM_Relationshipblock_ExtensionUtil as E;

/**
 * Implements hook_civicrm_config().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_config
 */
function relationshipblock_civicrm_config(&$config) {
  _relationshipblock_civix_civicrm_config($config);
}

/**
 * Implements hook_civicrm_xmlMenu().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_xmlMenu
 */
function relationshipblock_civicrm_xmlMenu(&$files) {
  _relationshipblock_civix_civicrm_xmlMenu($files);
}

/**
 * Implements hook_civicrm_install().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_install
 */
function relationshipblock_civicrm_install() {
  _relationshipblock_civix_civicrm_install();
}

/**
 * Implements hook_civicrm_postInstall().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_postInstall
 */
function relationshipblock_civicrm_postInstall() {
  _relationshipblock_civix_civicrm_postInstall();
}

/**
 * Implements hook_civicrm_uninstall().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_uninstall
 */
function relationshipblock_civicrm_uninstall() {
  _relationshipblock_civix_civicrm_uninstall();
}

/**
 * Implements hook_civicrm_enable().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_enable
 */
function relationshipblock_civicrm_enable() {
  _relationshipblock_civix_civicrm_enable();
}

/**
 * Implements hook_civicrm_disable().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_disable
 */
function relationshipblock_civicrm_disable() {
  _relationshipblock_civix_civicrm_disable();
}

/**
 * Implements hook_civicrm_upgrade().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_upgrade
 */
function relationshipblock_civicrm_upgrade($op, CRM_Queue_Queue $queue = NULL) {
  return _relationshipblock_civix_civicrm_upgrade($op, $queue);
}

/**
 * Implements hook_civicrm_managed().
 *
 * Generate a list of entities to create/deactivate/delete when this module
 * is installed, disabled, uninstalled.
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_managed
 */
function relationshipblock_civicrm_managed(&$entities) {
  _relationshipblock_civix_civicrm_managed($entities);
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
function relationshipblock_civicrm_caseTypes(&$caseTypes) {
  _relationshipblock_civix_civicrm_caseTypes($caseTypes);
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
function relationshipblock_civicrm_angularModules(&$angularModules) {
  _relationshipblock_civix_civicrm_angularModules($angularModules);
}

/**
 * Implements hook_civicrm_alterSettingsFolders().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_alterSettingsFolders
 */
function relationshipblock_civicrm_alterSettingsFolders(&$metaDataFolders = NULL) {
  _relationshipblock_civix_civicrm_alterSettingsFolders($metaDataFolders);
}

/**
 * Implements hook_civicrm_entityTypes().
 *
 * Declare entity types provided by this module.
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_entityTypes
 */
function relationshipblock_civicrm_entityTypes(&$entityTypes) {
  _relationshipblock_civix_civicrm_entityTypes($entityTypes);
}

// --- Functions below this ship commented out. Uncomment as required. ---

/**
 * Implements hook_civicrm_postProcess().
 */
function relationshipblock_civicrm_postProcess($formName, &$form) {
  if ($formName === 'CRM_Contact_Form_Relationship') {
    $form->ajaxResponse['reloadBlocks'][] = '#crm-relblock-content';
  }
}

/**
 * Implementation of hook_civicrm_managed
 *
 * Generate a list of entities to create/deactivate/delete when this module
 * is installed, disabled, uninstalled.
 */
function relationshipblock_civicrm_pageRun(&$page) {
  if (get_class($page) === 'CRM_Contact_Page_View_Summary') {
    if (($contactID = $page->getVar('_contactId')) !== FALSE) {
      try {
        if (CRM_Relationshipblock_Utils_RelationshipBlock::getDisplayedRelationshipTypes($contactID)) {
          CRM_Relationshipblock_Page_Inline_RelationshipBlock::addKeyRelationshipsBlock($page, $contactID);
          CRM_Core_Region::instance('contact-basic-info-right')->add(array(
            'template' => "CRM/Relationshipblock/ContactSummaryBlock.tpl"
          ));
        }
      }
      catch(Exception $e) {
        // oohhh we have an error. Give up hope.
        return;
      }
    }
    else {
      return;
    }
  }
}

/**
 * Implements hook_civicrm_navigationMenu().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_navigationMenu
 */
function relationshipblock_civicrm_navigationMenu(&$menu) {
  _relationshipblock_civix_navigationMenu($menu);
}

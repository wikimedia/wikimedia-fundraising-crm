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
 * Implements hook_civicrm_install().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_install
 */
function relationshipblock_civicrm_install() {
  _relationshipblock_civix_civicrm_install();
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
 * Implements hook_civicrm_postProcess().
 */
function relationshipblock_civicrm_postProcess($formName, &$form) {
  if ($formName === 'CRM_Contact_Form_Relationship') {
    $form->ajaxResponse['reloadBlocks'][] = '#crm-relblock-content';
  }
}

/**
 * Implements hook_civicrm_pageRun().
 */
function relationshipblock_civicrm_pageRun(&$page) {
  if (get_class($page) === 'CRM_Contact_Page_View_Summary') {
    if (($contactID = $page->getVar('_contactId')) !== FALSE) {
      try {
        if (CRM_Relationshipblock_Utils_RelationshipBlock::getDisplayedRelationshipTypes($contactID)) {
          CRM_Relationshipblock_Page_Inline_RelationshipBlock::addKeyRelationshipsBlock($page, $contactID);
          CRM_Core_Region::instance('contact-basic-info-right')->add(array(
            'template' => "CRM/Relationshipblock/ContactSummaryBlock.tpl",
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

/**
 * Implements hook_civicrm_contactSummaryBlocks().
 *
 * @link https://github.com/civicrm/org.civicrm.contactlayout
 */
function relationshipblock_civicrm_contactSummaryBlocks(&$blocks) {
  // Provide our own group for this block to visually distinguish it on the contact summary editor palette.
  $blocks += [
    'relationshipblock' => [
      'title' => ts('Relationships'),
      'icon' => 'fa-user-circle',
      'blocks' => [],
    ]
  ];
  $blocks['relationshipblock']['blocks']['relationshipblock'] = [
    'title' => ts('Key Relationships'),
    'tpl_file' => 'CRM/Relationshipblock/Page/Inline/RelationshipBlock.tpl',
    'sample' => [E::ts('Relationship %1', [1 => 1]), E::ts('Relationship %1', [1 => 2]), E::ts('Relationship %1', [1 => 3])],
    'edit' => 'civicrm/admin/reltype?reset=1',
    'system_default' => [0, 1],
  ];
}

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
 * Implements hook_civicrm_install().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_install
 */
function wmf_thankyou_civicrm_install() {
  _wmf_thankyou_civix_civicrm_install();
}

/**
 * Implements hook_civicrm_enable().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_enable
 */
function wmf_thankyou_civicrm_enable() {
  _wmf_thankyou_civix_civicrm_enable();
}

function wmf_thankyou_civicrm_links($op, $objectName, $objectId, &$links, &$mask, &$values) {
  //create a Send Invoice link with the context of the participant's order ID (a custom participant field)
  if ($objectName === 'Contribution' && $op === 'contribution.selector.row') {
    $links[] = [
      'name' => ts('Send Thank You'),
      'title' => ts('Send Thank You'),
      'url' => 'civicrm/wmf_thankyou',
      'qs' => "contribution_id=$objectId",
      'class' => 'crm-popup medium-popup',
      'weight' => 1,
    ];
    foreach ($links as $index => $link) {
      if ($link['name'] === 'Send Receipt') {
        unset($links[$index]);
      }
    }
  }
  if ($objectName === 'Contribution' && $op === 'contribution.selector.recurring') {
    $links[] = [
      'name' => ts('Send Monthly Convert Thank You'),
      'title' => ts('Send Monthly Convert Thank You'),
      'url' => 'civicrm/wmf_thankyou',
      'qs' => "contribution_recur_id=$objectId",
      'class' => 'crm-popup medium-popup',
      'weight' => 1,
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
  } catch (CRM_Core_Exception $e) {
    // This would most likely happen if viewing a deleted contact since we are not forcing
    // them to be returned. Keep calm & carry on.
  }
}

/**
 * Implements hook_civicrm_navigationMenu().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_navigationMenu
 */
function wmf_thankyou_civicrm_navigationMenu(&$menu) {
  _wmf_thankyou_civix_insert_navigation_menu($menu,
    'Administer/Customize Data and Screens', [
    'label' => E::ts('WMF Thank You configuration'),
    'name' => 'wmf_thank_you_configuration',
    'url' => 'civicrm/admin/setting/wmf-thankyou',
    'permission' => 'administer CiviCRM',
    'operator' => 'OR',
    'separator' => 0,
  ]);
  _wmf_thankyou_civix_navigationMenu($menu);
}

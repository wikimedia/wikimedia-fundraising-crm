<?php

require_once 'tutorial.civix.php';

/**
 * Implements hook_civicrm_config().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_config
 */
function tutorial_civicrm_config(&$config) {
  _tutorial_civix_civicrm_config($config);
}

/**
 * Implements hook_civicrm_install().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_install
 */
function tutorial_civicrm_install() {
  _tutorial_civix_civicrm_install();
}

/**
 * Implements hook_civicrm_enable().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_enable
 */
function tutorial_civicrm_enable() {
  _tutorial_civix_civicrm_enable();
}

/**
 * Implements hook_civicrm_buildForm().
 */
function tutorial_civicrm_buildForm($formName, &$form) {
  CRM_Tutorial_BAO_Tutorial::load(implode('/', $form->urlPath));
}

/**
 * Implements hook_civicrm_pageRun().
 */
function tutorial_civicrm_pageRun(&$page) {
  CRM_Tutorial_BAO_Tutorial::load(implode('/', $page->urlPath));
}

/**
 * Implements hook_civicrm_alterAPIPermissions().
 */
function tutorial_civicrm_alterAPIPermissions($entity, $action, &$params, &$permissions) {
  if ($entity === 'tutorial') {
    $permissions['tutorial']['mark'] = ['access CiviCRM'];
  }
}

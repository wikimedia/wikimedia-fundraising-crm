<?php

require_once 'unsubscribeemail.civix.php';
use CRM_Unsubscribeemail_ExtensionUtil as E;

/**
 * Implements hook_civicrm_config().
 */
function unsubscribeemail_civicrm_config(&$config) {
  _unsubscribeemail_civix_civicrm_config($config);
}

/**
 * Implements hook_civicrm_install().
 */
function unsubscribeemail_civicrm_install() {
  _unsubscribeemail_civix_civicrm_install();
}

/**
 * Implements hook_civicrm_enable().
 */
function unsubscribeemail_civicrm_enable() {
  _unsubscribeemail_civix_civicrm_enable();
}

/**
 * @param array $permissions
 */
function unsubscribeemail_civicrm_permission(&$permissions) {
  $prefix = 'CiviCRM UnsubscribeEmail: ';
  $permissions['access unsubscribe email form'] = [
    $prefix . 'access unsubscribe email form',
    E::ts('Access the form to unsubscribe any contact by entering email address.'),
  ];
}

/**
 * Implements hook_civicrm_navigationMenu().
 */
function unsubscribeemail_civicrm_navigationMenu(&$menu) {
  _unsubscribeemail_civix_insert_navigation_menu($menu, 'Contacts', [
    'label' => E::ts('Unsubscribe email'),
    'name' => 'unsubscribe_email',
    'url' => 'civicrm/a/#/email/unsubscribe',
    'permission' => 'access unsubscribe email form,edit all contacts',
    'operator' => 'OR',
  ]);
  _unsubscribeemail_civix_navigationMenu($menu);
}

<?php

require_once 'assignee.civix.php';

/**
 * Implements hook_civicrm_config().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_config
 */
function assignee_civicrm_config(&$config) {
  _assignee_civix_civicrm_config($config);
}

/**
 * Implements hook_civicrm_xmlMenu().
 *
 * @param array $files
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_xmlMenu
 */
function assignee_civicrm_xmlMenu(&$files) {
  _assignee_civix_civicrm_xmlMenu($files);
}

/**
 * Implements hook_civicrm_install().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_install
 */
function assignee_civicrm_install() {
  _assignee_civix_civicrm_install();
}

/**
 * Implements hook_civicrm_uninstall().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_uninstall
 */
function assignee_civicrm_uninstall() {
  _assignee_civix_civicrm_uninstall();
}

/**
 * Implements hook_civicrm_enable().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_enable
 */
function assignee_civicrm_enable() {
  _assignee_civix_civicrm_enable();
}

/**
 * Implements hook_civicrm_disable().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_disable
 */
function assignee_civicrm_disable() {
  _assignee_civix_civicrm_disable();
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
function assignee_civicrm_upgrade($op, CRM_Queue_Queue $queue = NULL) {
  return _assignee_civix_civicrm_upgrade($op, $queue);
}

/**
 * Implements hook_civicrm_managed().
 *
 * Generate a list of entities to create/deactivate/delete when this module
 * is installed, disabled, uninstalled.
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_managed
 */
function assignee_civicrm_managed(&$entities) {
  _assignee_civix_civicrm_managed($entities);
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
function assignee_civicrm_caseTypes(&$caseTypes) {
  _assignee_civix_civicrm_caseTypes($caseTypes);
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
function assignee_civicrm_angularModules(&$angularModules) {
_assignee_civix_civicrm_angularModules($angularModules);
}

/**
 * Implements hook_civicrm_alterSettingsFolders().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_alterSettingsFolders
 */
function assignee_civicrm_alterSettingsFolders(&$metaDataFolders = NULL) {
  _assignee_civix_civicrm_alterSettingsFolders($metaDataFolders);
}

/**
 * Implements hook_civicrm_preProcess().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_preProcess
 */
function assignee_civicrm_preProcess($formName, &$form) {
  if (is_a($form, 'CRM_Activity_Form_Activity')) {
    $assignee_group = Civi::settings()->get('assignee_group');   # 4.7
    if ($assignee_group) {
      $form->_fields['assignee_contact_id']['attributes']['api']['params']['group'] = $assignee_group;
      $form->_fields['followup_assignee_contact_id']['attributes']['api']['params']['group'] = $assignee_group;
    }
  }
}

/**
 * Implements hook_civicrm_buildForm().
 * 
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_buildForm
 */
function assignee_civicrm_buildForm($formName, &$form) {
    if (is_a($form, 'CRM_Activity_Form_Activity') AND Civi::settings()->get('assignee_as_source')) {
      $form->setDefaults(array('assignee_contact_id' => $form->_defaultValues['source_contact_id']));
    }
}

/**
 * Implements hook_civicrm_navigationMenu().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_navigationMenu
 */
function assignee_civicrm_navigationMenu(&$menu) {
  _assignee_civix_insert_navigation_menu($menu, "Administer/System Settings", array(
    'label' => ts('Activity Assignee Settings', array('domain' => 'uk.squiffle.assignee')),
    'name' => 'the_page',
    'url' => 'civicrm/assigneesettings',
    'permission' => 'administer CiviCRM',
    'operator' => 'OR',
    'separator' => 0,
  ));
  _assignee_civix_navigationMenu($menu);
} 

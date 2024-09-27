<?php

require_once 'assignee.civix.php';

/**
 * Implements hook_civicrm_config().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_config
 */
function assignee_civicrm_config(&$config) {
  _assignee_civix_civicrm_config($config);
}

/**
 * Implements hook_civicrm_install().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_install
 */
function assignee_civicrm_install() {
  _assignee_civix_civicrm_install();
}

/**
 * Implements hook_civicrm_enable().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_enable
 */
function assignee_civicrm_enable() {
  _assignee_civix_civicrm_enable();
}

/**
 * Implements hook_civicrm_preProcess().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_preProcess
 */
function assignee_civicrm_preProcess($formName, &$form) {
  if (is_a($form, 'CRM_Activity_Form_Activity')) {
    $assignee_group = Civi::settings()->get('assignee_group');
    if ($assignee_group) {
      $form->_fields['assignee_contact_id']['attributes']['api']['params']['group'] = $assignee_group;
      $form->_fields['followup_assignee_contact_id']['attributes']['api']['params']['group'] = $assignee_group;
      // Remove error that allows users to accidentally (or even on purpose) bypass
      // the restriction https://lab.civicrm.org/extensions/assignee/-/issues/2
      $form->assign('disable_swap_button', TRUE);
    }
  }
}

/**
 * Implements hook_civicrm_buildForm().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_buildForm
 */
function assignee_civicrm_buildForm($formName, &$form) {
  if (is_a($form, 'CRM_Activity_Form_Activity')) {
    $assignees = explode(",", Civi::settings()->get('assignee_contacts') ?? '');
    if (Civi::settings()->get('assignee_as_source')) {
      $assignees[] = $form->_defaultValues['source_contact_id'];
    }
    $assignees = array_unique(array_filter($assignees));
    if ($assignees) {
      $form->setDefaults(['assignee_contact_id' => implode(",", $assignees)]);
    }
  }
}

/**
 * Implements hook_civicrm_navigationMenu().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_navigationMenu
 */
function assignee_civicrm_navigationMenu(&$menu) {
  _assignee_civix_insert_navigation_menu($menu, "Administer/System Settings", [
    'label' => ts('Activity Assignee Settings', ['domain' => 'assignee']),
    'name' => 'assignee-settings',
    'url' => 'civicrm/settings/assignee',
    'permission' => 'administer CiviCRM',
  ]);
  _assignee_civix_navigationMenu($menu);
}

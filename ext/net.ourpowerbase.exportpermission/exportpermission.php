<?php

define('EXPORT_PERMISSION_NAME', 'access export menu');
define('PDF_PERMISSION_NAME', 'access pdf menu');
define('PRINT_PERMISSION_NAME', 'access print menu');
define('LABEL_PERMISSION_NAME', 'access mailing labels menu');

require_once 'exportpermission.civix.php';
use CRM_Exportpermission_ExtensionUtil as E;

/**
 * Implements hook_civicrm_config().
 */
function exportpermission_civicrm_config(&$config) {
  _exportpermission_civix_civicrm_config($config);
}

/**
 * Implements hook_civicrm_install().
 */
function exportpermission_civicrm_install() {
  _exportpermission_civix_civicrm_install();
}

/**
 * Implements hook_civicrm_enable().
 */
function exportpermission_civicrm_enable() {
  _exportpermission_civix_civicrm_enable();
}

/**
 * Implements hook_civicrm_permission().
 *
 * @see CRM_Utils_Hook::permission()
 * @param array $permissions
 */
function exportpermission_civicrm_permission(&$permissions) {
  $permissions[EXPORT_PERMISSION_NAME] = [
    'label' => E::ts('CiviCRM Export Permissions') . ': ' . E::ts('access export menu'),
    'description' => E::ts('Access "Export as CSV" drop down menu item from actions menu on after search/report'),
  ];
  $permissions[PRINT_PERMISSION_NAME] = [
    'label' => E::ts('CiviCRM Export Permissions') . ': ' . E::ts('access print menu'),
    'description' => E::ts('Access "Print" drop down menu item from actions menu on search/report'),
  ];
  $permissions[PDF_PERMISSION_NAME] = [
    'label' => E::ts('CiviCRM Export Permissions') . ': ' . E::ts('access print pdf menu'),
    'description' => E::ts('Access "Print/Merge document (PDF Letter)" drop down menu item from actions menu on search/report'),
  ];
  $permissions[LABEL_PERMISSION_NAME] = [
    'label' => E::ts('CiviCRM Export Permissions') . ': ' . E::ts('access mailing labels menu'),
    'description' => E::ts('Access "Print Mailing Labels" drop down menu item from actions menu on search/report'),
  ];
}

/**
 * Implements hook_civicrm_searchTasks();
 *
 * @param string $objectName
 * @param array $tasks
 */
function exportpermission_civicrm_searchTasks($objectName, &$tasks) {
  if (!CRM_Core_Permission::check(EXPORT_PERMISSION_NAME)) {
    unset($tasks[CRM_Core_Task::TASK_EXPORT]);
  }
  if (!CRM_Core_Permission::check(PDF_PERMISSION_NAME)) {
    unset($tasks[CRM_Core_Task::PDF_LETTER]);
  }
  if (!CRM_Core_Permission::check(PRINT_PERMISSION_NAME)) {
    unset($tasks[CRM_Core_Task::TASK_PRINT]);
  }
  if (!CRM_Core_Permission::check(LABEL_PERMISSION_NAME)) {
    unset($tasks[CRM_Core_Task::LABEL_CONTACTS]);
  }
}

/**
 * Implementation of hook_civicrm_alterReportVar()
 *
 * @param string $varType
 * @param array $var
 * @param CRM_Report_Form $reportForm
 *
 */
function exportpermission_civicrm_alterReportVar($varType, &$var, $reportForm) {
  switch ($varType) {
    case 'actions':
      if (!CRM_Core_Permission::check(EXPORT_PERMISSION_NAME)) {
        unset($var['report_instance.csv']);
      }
      if (!CRM_Core_Permission::check(PDF_PERMISSION_NAME)) {
        unset($var['report_instance.pdf']);
      }
      if (!CRM_Core_Permission::check(PRINT_PERMISSION_NAME)) {
        unset($var['report_instance.print']);
      }
      break;
  }
}

/**
 * Implements hook_civicrm_buildForm().
 *
 * @param string $formName
 * @param CRM_Core_Form $form
 */
function exportpermission_civicrm_buildForm($formName, &$form) {
  $bounce = FALSE;
  // Check for permissions and return permission denied if user tries to access
  //   forms directly and don't have permission.
  // Note: This relies on matching form names and may not include all forms.
  if (strstr($formName, 'Export_Form') && !CRM_Core_Permission::check(EXPORT_PERMISSION_NAME)) {
    $bounce = TRUE;
  }
  elseif (strstr($formName, 'Task_Print') && !CRM_Core_Permission::check(PRINT_PERMISSION_NAME)) {
    $bounce = TRUE;
  }
  elseif (strstr($formName, 'Task_PDF') && !CRM_Core_Permission::check(PDF_PERMISSION_NAME)) {
    $bounce = TRUE;
  }
  elseif (strstr($formName, 'Task_Label') && !CRM_Core_Permission::check(LABEL_PERMISSION_NAME)) {
    $bounce = TRUE;
  }

  if ($bounce) {
    CRM_Core_Error::statusBounce(E::ts('You do not have permission to access this page.'));
  }
}

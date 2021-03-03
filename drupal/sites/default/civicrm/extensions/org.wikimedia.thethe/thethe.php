<?php

require_once 'thethe.civix.php';
use CRM_Thethe_ExtensionUtil as E;

/**
 * Implements hook_civicrm_config().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_config
 */
function thethe_civicrm_config(&$config) {
  _thethe_civix_civicrm_config($config);
}

/**
 * Implements hook_civicrm_xmlMenu().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_xmlMenu
 */
function thethe_civicrm_xmlMenu(&$files) {
  _thethe_civix_civicrm_xmlMenu($files);
}

/**
 * Implements hook_civicrm_install().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_install
 */
function thethe_civicrm_install() {
  _thethe_civix_civicrm_install();
}

/**
 * Implements hook_civicrm_postInstall().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_postInstall
 */
function thethe_civicrm_postInstall() {
  _thethe_civix_civicrm_postInstall();
}

/**
 * Implements hook_civicrm_uninstall().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_uninstall
 */
function thethe_civicrm_uninstall() {
  _thethe_civix_civicrm_uninstall();
}

/**
 * Implements hook_civicrm_enable().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_enable
 */
function thethe_civicrm_enable() {
  _thethe_civix_civicrm_enable();
}

/**
 * Implements hook_civicrm_disable().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_disable
 */
function thethe_civicrm_disable() {
  _thethe_civix_civicrm_disable();
}

/**
 * Implements hook_civicrm_upgrade().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_upgrade
 */
function thethe_civicrm_upgrade($op, CRM_Queue_Queue $queue = NULL) {
  return _thethe_civix_civicrm_upgrade($op, $queue);
}

/**
 * Implements hook_civicrm_managed().
 *
 * Generate a list of entities to create/deactivate/delete when this module
 * is installed, disabled, uninstalled.
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_managed
 */
function thethe_civicrm_managed(&$entities) {
  _thethe_civix_civicrm_managed($entities);
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
function thethe_civicrm_caseTypes(&$caseTypes) {
  _thethe_civix_civicrm_caseTypes($caseTypes);
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
function thethe_civicrm_angularModules(&$angularModules) {
  _thethe_civix_civicrm_angularModules($angularModules);
}

/**
 * Implements hook_civicrm_alterSettingsFolders().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_alterSettingsFolders
 */
function thethe_civicrm_alterSettingsFolders(&$metaDataFolders = NULL) {
  _thethe_civix_civicrm_alterSettingsFolders($metaDataFolders);
}

/**
 * Implements hook_civicrm_entityTypes().
 *
 * Declare entity types provided by this module.
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_entityTypes
 */
function thethe_civicrm_entityTypes(&$entityTypes) {
  _thethe_civix_civicrm_entityTypes($entityTypes);
}

/**
 * Implements hook_pre().
 *
 * @param $op
 * @param $objectName
 * @param $id
 * @param $params
 */
function thethe_civicrm_pre($op, $objectName, $id, &$params) {
  if ($objectName === 'Organization') {
    if (isset($params['organization_name'])) {

      $params['sort_name'] = $params['organization_name'];
      foreach (thethe_get_setting('prefix') as $string) {
        if (strtolower(substr($params['sort_name'], 0, strlen($string))) === $string) {
          $params['sort_name'] = substr($params['sort_name'], strlen($string));
        }
      }

      foreach (thethe_get_setting('suffix') as $string) {
        $suffixStart = strlen($params['sort_name']) - strlen($string);
        if (strtolower(substr($params['sort_name'], $suffixStart, strlen($string))) === $string) {
          $params['sort_name'] = substr($params['sort_name'], 0, $suffixStart);
        }
      }

      foreach (thethe_get_setting('anywhere') as $string) {
        $params['sort_name'] = str_replace($string, '', $params['sort_name']);
      }
      $params['sort_name'] = trim($params['sort_name']);
    }
  }
}

/**
 * Get the the settings in stdised array.
 *
 * We are a bit flexible in what we support -
 *  - the
 *  - 'the '
 *  - 'the ', 'a ',
 *  - ['the ']
 *
 * @param string $settingName
 *   - prefix
 *   - suffix
 *   - anywhere
 *
 * @param string $entity
 *   - currently only org supported
 *
 * @return array
 */
function thethe_get_setting($settingName, $entity = 'org') {
  $strings = Civi::settings()->get('thethe_' . $entity . '_' . $settingName . '_strings');
  if (empty($strings)) {
    return [];
  }
  if (!is_array($strings)) {
    $strings = explode(',', $strings);
  }
  foreach ($strings as $index => $string) {
    $strings[$index] = strtolower(trim($string, "'"));
  }
  return $strings;
}

// --- Functions below this ship commented out. Uncomment as required. ---

/**
 * Implements hook_civicrm_preProcess().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_preProcess
 *
function thethe_civicrm_preProcess($formName, &$form) {

} // */

/**
 * Implements hook_civicrm_navigationMenu().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_navigationMenu
 */
function thethe_civicrm_navigationMenu(&$menu) {
  _thethe_civix_insert_navigation_menu($menu, 'Administer/Customize Data and Screens', array(
    'label' => E::ts('Organization sort name Settings'),
    'name' => 'the_the_settings',
    'url' => 'civicrm/admin/setting/thethe',
    'permission' => 'administer CiviCRM',
    'operator' => 'OR',
    'separator' => 0,
  ));
  _thethe_civix_navigationMenu($menu);
}

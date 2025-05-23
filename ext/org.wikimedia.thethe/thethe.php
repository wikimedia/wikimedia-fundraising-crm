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
 * Implements hook_civicrm_install().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_install
 */
function thethe_civicrm_install() {
  _thethe_civix_civicrm_install();
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

 // */

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

<?php

require_once 'datachecks.civix.php';

/**
 * Implements hook_civicrm_config().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_config
 */
function datachecks_civicrm_config(&$config) {
  _datachecks_civix_civicrm_config($config);
}

/**
 * Implements hook_civicrm_xmlMenu().
 *
 * @param array $files
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_xmlMenu
 */
function datachecks_civicrm_xmlMenu(&$files) {
  _datachecks_civix_civicrm_xmlMenu($files);
}

/**
 * Implements hook_civicrm_install().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_install
 */
function datachecks_civicrm_install() {
  _datachecks_civix_civicrm_install();
}

/**
 * Implements hook_civicrm_uninstall().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_uninstall
 */
function datachecks_civicrm_uninstall() {
  _datachecks_civix_civicrm_uninstall();
}

/**
 * Implements hook_civicrm_enable().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_enable
 */
function datachecks_civicrm_enable() {
  _datachecks_civix_civicrm_enable();
}

/**
 * Implements hook_civicrm_disable().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_disable
 */
function datachecks_civicrm_disable() {
  _datachecks_civix_civicrm_disable();
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
function datachecks_civicrm_upgrade($op, CRM_Queue_Queue $queue = NULL) {
  return _datachecks_civix_civicrm_upgrade($op, $queue);
}

/**
 * Implements hook_civicrm_managed().
 *
 * Generate a list of entities to create/deactivate/delete when this module
 * is installed, disabled, uninstalled.
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_managed
 */
function datachecks_civicrm_managed(&$entities) {
  _datachecks_civix_civicrm_managed($entities);
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
function datachecks_civicrm_caseTypes(&$caseTypes) {
  _datachecks_civix_civicrm_caseTypes($caseTypes);
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
function datachecks_civicrm_angularModules(&$angularModules) {
_datachecks_civix_civicrm_angularModules($angularModules);
}

/**
 * Implements hook_civicrm_alterSettingsFolders().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_alterSettingsFolders
 */
function datachecks_civicrm_alterSettingsFolders(&$metaDataFolders = NULL) {
  _datachecks_civix_civicrm_alterSettingsFolders($metaDataFolders);
}

/**
 * Implements the datachecks_checks hook.
 *
 * @param $checks
 */
function datachecks_civicrm_datacheck_checks(&$checks) {
  $checks['PrimaryLocation'] = array(
    'name' => 'PrimaryLocation',
    'class' => 'CRM_Datachecks_PrimaryLocation',
    'description' => ts('Contacts who have addresses, emails etc where none is marked primary'),
    'label' => ts('Primary location fix'),
    'module' => 'org.wikimedia.datachecks',
  );
  $checks['DuplicateLocation'] = array(
    'name' => 'DuplicateLocation',
    'class' => 'CRM_Datachecks_DuplicateLocation',
    'description' => ts('Contacts who have more than one address (or email etc) of the same location type'),
    'label' => ts('Duplicate location fix'),
    'module' => 'org.wikimedia.datachecks',
    'fix_options' => array(
      'type' => CRM_Utils_Type::T_STRING,
      'title' => ts('Options for fixing'),
      'options' => array(
        'delete_exact_duplicates' => ts('Delete any exact duplicates'),
        // Options to follow will include delete inferior duplicates - e.g where the country
        // only has been given vs a full address and change location type. For the latter need to
        // figure out the format - presumably it will be passed in as a parameter.
        // perhaps it will be 'change_alternate_to_home', 'change_alternate_to_billing' etc.
      )
    ),
  );
}

/**
 * Get available checks.
 *
 * @return array
 */
function datachecks_civicrm_data_fix_get_options() {
  $checks = array();
  CRM_Datachecks_Hook::dataCheckGetChecks($checks);
  return $checks;
}

/**
 * Get options formatted as name/label.
 *
 * @return array
 */
function datachecks_civicrm_data_get_option_pairs() {
  $options = datachecks_civicrm_data_fix_get_options();
  $return = array();
  foreach ($options as $option) {
    $return[$option['name']] = $option['label'];
  }
  return $return;
}

/**
 * Functions below this ship commented out. Uncomment as required.
 *

/**
 * Implements hook_civicrm_preProcess().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_preProcess
 *
function datachecks_civicrm_preProcess($formName, &$form) {

} // */

/**
 * Implements hook_civicrm_navigationMenu().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_navigationMenu
 *
function datachecks_civicrm_navigationMenu(&$menu) {
  _datachecks_civix_insert_navigation_menu($menu, NULL, array(
    'label' => ts('The Page', array('domain' => 'org.wikimedia.datachecks')),
    'name' => 'the_page',
    'url' => 'civicrm/the-page',
    'permission' => 'access CiviReport,access CiviContribute',
    'operator' => 'OR',
    'separator' => 0,
  ));
  _datachecks_civix_navigationMenu($menu);
} // */

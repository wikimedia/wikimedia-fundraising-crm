<?php

require_once 'omnimail.civix.php';

// checking if the file exists allows compilation elsewhere if desired.
if (file_exists( __DIR__ . '/vendor/autoload.php')) {
  require_once __DIR__ . '/vendor/autoload.php';
}

/**
 * Implements hook_civicrm_config().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_config
 */
function omnimail_civicrm_config(&$config) {
  _omnimail_civix_civicrm_config($config);
}

/**
 * Implements hook_civicrm_xmlMenu().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_xmlMenu
 */
function omnimail_civicrm_xmlMenu(&$files) {
  _omnimail_civix_civicrm_xmlMenu($files);
}

/**
 * Implements hook_civicrm_install().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_install
 */
function omnimail_civicrm_install() {
  _omnimail_civix_civicrm_install();
}

/**
 * Implements hook_civicrm_postInstall().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_postInstall
 */
function omnimail_civicrm_postInstall() {
  _omnimail_civix_civicrm_postInstall();
}

/**
 * Implements hook_civicrm_uninstall().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_uninstall
 */
function omnimail_civicrm_uninstall() {
  _omnimail_civix_civicrm_uninstall();
}

/**
 * Implements hook_civicrm_enable().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_enable
 */
function omnimail_civicrm_enable() {
  _omnimail_civix_civicrm_enable();
}

/**
 * Implements hook_civicrm_disable().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_disable
 */
function omnimail_civicrm_disable() {
  _omnimail_civix_civicrm_disable();
}

/**
 * Implements hook_civicrm_upgrade().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_upgrade
 */
function omnimail_civicrm_upgrade($op, CRM_Queue_Queue $queue = NULL) {
  return _omnimail_civix_civicrm_upgrade($op, $queue);
}

/**
 * Implements hook_civicrm_managed().
 *
 * Generate a list of entities to create/deactivate/delete when this module
 * is installed, disabled, uninstalled.
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_managed
 */
function omnimail_civicrm_managed(&$entities) {
  _omnimail_civix_civicrm_managed($entities);
}

/**
 * Implements hook_civicrm_entityTypes.
 *
 * @param array $entityTypes
 *   Registered entity types.
 */
function omnimail_civicrm_entityTypes(&$entityTypes) {
  $entityTypes['CRM_Omnimail_DAO_MailingProviderData'] = array(
    'name' => 'MailingProviderData',
    'class' => 'CRM_Omnimail_DAO_MailingProviderData',
    'table' => 'civicrm_maiing_provider_data',
  );
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
function omnimail_civicrm_caseTypes(&$caseTypes) {
  _omnimail_civix_civicrm_caseTypes($caseTypes);
}

/**
 * Implements hook_civicrm_angularModules().
 *
 * Generate a list of Angular modules.
 * Generate a list of Angular modules.
 *
 * Note: This hook only runs in CiviCRM 4.5+. It may
 * use features only available in v4.6+.
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_angularModules
 */
function omnimail_civicrm_angularModules(&$angularModules) {
  _omnimail_civix_civicrm_angularModules($angularModules);
}

/**
 * Implements hook_civicrm_alterSettingsFolders().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_alterSettingsFolders
 */
function omnimail_civicrm_alterSettingsFolders(&$metaDataFolders = NULL) {
  _omnimail_civix_civicrm_alterSettingsFolders($metaDataFolders);
}

/**
 * @param string $tabsetName
 * @param array $tabs
 * @param array $context
 */
function omnimail_civicrm_tabset($tabsetName, &$tabs, $context) {
  // early return while I finish this off.
  return;
  if ($tabsetName == 'civicrm/contact/view') {
    $contactID = $context['contact_id'];
      $url = CRM_Utils_System::url('civicrm/contact/mailings/view', "reset=1&snippet=json&force=1&cid=$contactID");
      //add a new Volunteer tab along with url
      $tab['mailing_data'] = array(
        'title' => ts('Mailings'),
        'url' => $url,
        'valid' => 1,
        'active' => 1,
        'current' => FALSE,
        'class' => 'livePage',
      );
    //Insert this tab into position 4
    $tabs = array_merge(
      array_slice($tabs, 0, 4),
      $tab,
      array_slice($tabs, 4)
    );
  }
}
/**
 * Keep mailing provider data out of log tables.
 *
 * @param array $logTableSpec
 */
function omnimail_civicrm_alterLogTables(&$logTableSpec) {
  unset($logTableSpec['civicrm_mailing_provider_data']);
}
// --- Functions below this ship commented out. Uncomment as required. ---

/**
 * Implements hook_civicrm_preProcess().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_preProcess
 *
function omnimail_civicrm_preProcess($formName, &$form) {

} // */

/**
 * Implements hook_civicrm_navigationMenu().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_navigationMenu
 *
function omnimail_civicrm_navigationMenu(&$menu) {
  _omnimail_civix_insert_navigation_menu($menu, NULL, array(
    'label' => ts('The Page', array('domain' => 'org.wikimedia.omnimail')),
    'name' => 'the_page',
    'url' => 'civicrm/the-page',
    'permission' => 'access CiviReport,access CiviContribute',
    'operator' => 'OR',
    'separator' => 0,
  ));
  _omnimail_civix_navigationMenu($menu);
} // */

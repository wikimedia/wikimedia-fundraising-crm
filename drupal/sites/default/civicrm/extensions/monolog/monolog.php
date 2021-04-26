<?php

require_once 'monolog.civix.php';
// phpcs:disable
use CRM_Monolog_ExtensionUtil as E;
// phpcs:enable
// This is for hook_civicrm_container
use Symfony\Component\DependencyInjection\Definition;

// checking if the file exists allows compilation elsewhere if desired.
if (file_exists( __DIR__ . '/vendor/autoload.php')) {
  require_once __DIR__ . '/vendor/autoload.php';
}

/**
 * Implements hook_civicrm_config().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_config/
 */
function monolog_civicrm_config(&$config) {
  _monolog_civix_civicrm_config($config);
}

/**
 * Implements hook_civicrm_xmlMenu().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_xmlMenu
 */
function monolog_civicrm_xmlMenu(&$files) {
  _monolog_civix_civicrm_xmlMenu($files);
}

/**
 * Implements hook_civicrm_install().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_install
 */
function monolog_civicrm_install() {
  _monolog_civix_civicrm_install();
}

/**
 * Implements hook_civicrm_postInstall().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_postInstall
 */
function monolog_civicrm_postInstall() {
  _monolog_civix_civicrm_postInstall();
}

/**
 * Implements hook_civicrm_uninstall().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_uninstall
 */
function monolog_civicrm_uninstall() {
  _monolog_civix_civicrm_uninstall();
}

/**
 * Implements hook_civicrm_enable().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_enable
 */
function monolog_civicrm_enable() {
  _monolog_civix_civicrm_enable();
}

/**
 * Implements hook_civicrm_disable().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_disable
 */
function monolog_civicrm_disable() {
  $logManager = Civi::service('psr_log_manager');
  if (is_a($logManager, '\Civi\MonoLog\MonologManager')) {
    $logManager->disable();
  }
  _monolog_civix_civicrm_disable();
}

/**
 * Implements hook_civicrm_upgrade().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_upgrade
 */
function monolog_civicrm_upgrade($op, CRM_Queue_Queue $queue = NULL) {
  return _monolog_civix_civicrm_upgrade($op, $queue);
}

/**
 * Implements hook_civicrm_managed().
 *
 * Generate a list of entities to create/deactivate/delete when this module
 * is installed, disabled, uninstalled.
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_managed
 */
function monolog_civicrm_managed(&$entities) {
  _monolog_civix_civicrm_managed($entities);
}

/**
 * Implements hook_civicrm_caseTypes().
 *
 * Generate a list of case-types.
 *
 * Note: This hook only runs in CiviCRM 4.4+.
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_caseTypes
 */
function monolog_civicrm_caseTypes(&$caseTypes) {
  _monolog_civix_civicrm_caseTypes($caseTypes);
}

/**
 * Implements hook_civicrm_angularModules().
 *
 * Generate a list of Angular modules.
 *
 * Note: This hook only runs in CiviCRM 4.5+. It may
 * use features only available in v4.6+.
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_angularModules
 */
function monolog_civicrm_angularModules(&$angularModules) {
  _monolog_civix_civicrm_angularModules($angularModules);
}

/**
 * Implements hook_civicrm_alterSettingsFolders().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_alterSettingsFolders
 */
function monolog_civicrm_alterSettingsFolders(&$metaDataFolders = NULL) {
  $c = Civi::container();
  _monolog_civix_civicrm_alterSettingsFolders($metaDataFolders);
}

/**
 * Implements hook_civicrm_entityTypes().
 *
 * Declare entity types provided by this module.
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_entityTypes
 */
function monolog_civicrm_entityTypes(&$entityTypes) {
  _monolog_civix_civicrm_entityTypes($entityTypes);
}

/**
 * Implements hook_civicrm_themes().
 */
function monolog_civicrm_themes(&$themes) {
  _monolog_civix_civicrm_themes($themes);
}

// --- Functions below this ship commented out. Uncomment as required. ---

/**
 * Implements hook_civicrm_preProcess().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_preProcess
 */
//function monolog_civicrm_preProcess($formName, &$form) {
//
//}

/**
 * Implements hook_civicrm_navigationMenu().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_navigationMenu
 */
function monolog_civicrm_navigationMenu(&$menu) {
  _monolog_civix_insert_navigation_menu($menu, 'Administer/Customize Data and Screens', [
    'label' => E::ts('Monolog'),
    'name' => 'Monolog',
    'url' => '',
    'permission' => 'administer CiviCRM system',
    'operator' => 'OR',
    'separator' => 0,
  ]);
  _monolog_civix_navigationMenu($menu);

  _monolog_civix_insert_navigation_menu($menu, 'Administer/Customize Data and Screens/Monolog', [
    'label' => E::ts('Add Monolog'),
    'name' => 'monolog_add',
    'url' => 'civicrm/monolog',
    'permission' => 'administer CiviCRM system',
    'operator' => 'OR',
    'separator' => 0,
  ]);

  _monolog_civix_insert_navigation_menu($menu, 'Administer/Customize Data and Screens/Monolog', [
    'label' => E::ts('Configured Monologs'),
    'name' => 'monolog_search',
    'url' => 'civicrm/search#/display/Monolog%20configuration/Monologs',
    'permission' => 'administer CiviCRM system',
    'operator' => 'OR',
    'separator' => 0,
  ]);

  _monolog_civix_navigationMenu($menu);
}

function monolog_civicrm_container($container) {
  $container->setDefinition('psr_log_manager', new Definition('\Civi\MonoLog\MonologManager', []))->setPublic(TRUE);
}

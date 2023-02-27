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
 * Implements hook_civicrm_install().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_install
 */
function monolog_civicrm_install() {
  _monolog_civix_civicrm_install();
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
}

/**
 * Implements hook_civicrm_alterSettingsFolders().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_alterSettingsFolders
 */
function monolog_civicrm_alterSettingsFolders(&$metaDataFolders = NULL) {
  $c = Civi::container();
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

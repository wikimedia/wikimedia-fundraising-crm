<?php

require_once 'deduper.civix.php';
require_once 'deduper.php';
use Symfony\Component\DependencyInjection\Definition;

/**
 * Implements hook_civicrm_config().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_config/
 */
function dedupetools_civicrm_config(&$config) {
  deduper_civicrm_config($config);
}

/**
 * Implements hook_civicrm_xmlMenu().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_xmlMenu
 */
function dedupetools_civicrm_xmlMenu(&$files) {
  deduper_civicrm_xmlMenu($files);
}

/**
 * Implements hook_civicrm_install().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_install
 */
function dedupetools_civicrm_install() {
  deduper_civicrm_install();
}

/**
 * Implements hook_civicrm_postInstall().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_postInstall
 */
function dedupetools_civicrm_postInstall() {
  deduper_civicrm_postInstall();
}

/**
 * Implements hook_civicrm_uninstall().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_uninstall
 */
function dedupetools_civicrm_uninstall() {
  deduper_civicrm_uninstall();
}

/**
 * Implements hook_civicrm_enable().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_enable
 */
function dedupetools_civicrm_enable() {
  deduper_civicrm_enable();
}

/**
 * Implements hook_civicrm_disable().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_disable
 */
function dedupetools_civicrm_disable() {
  deduper_civicrm_disable();
}

/**
 * Implements hook_civicrm_upgrade().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_upgrade
 */
function dedupetools_civicrm_upgrade($op, CRM_Queue_Queue $queue = NULL) {
  return deduper_civicrm_upgrade($op, $queue);
}

/**
 * Implements hook_civicrm_managed().
 *
 * Generate a list of entities to create/deactivate/delete when this module
 * is installed, disabled, uninstalled.
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_managed
 */
function dedupetools_civicrm_managed(&$entities) {
  deduper_civicrm_managed($entities);
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
function dedupetools_civicrm_caseTypes(&$caseTypes) {
  deduper_civicrm_caseTypes($caseTypes);
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
function dedupetools_civicrm_angularModules(&$angularModules) {
  deduper_civicrm_angularModules($angularModules);
}

/**
 * Implements hook_civicrm_alterSettingsFolders().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_alterSettingsFolders
 */
function dedupetools_civicrm_alterSettingsFolders(&$metaDataFolders = NULL) {
  deduper_civicrm_alterSettingsFolders($metaDataFolders);
}

/**
 * Add dedupe searches to actions available.
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_summaryActions
 *
 * @param array $actions
 * @param int $contactID
 */
function dedupetools_civicrm_summaryActions(&$actions, $contactID) {
  deduper_civicrm_summaryActions($actions, $contactID);
}

/**
 * Keep merge conflict analysis out of log tables. It is temporary data.
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_alterLogTables
 *
 * @param array $logTableSpec
 */
function dedupetools_civicrm_alterLogTables(&$logTableSpec) {
  deduper_civicrm_alterLogTables($logTableSpec);
}

/**
 * This hook is called to display the list of actions allowed after doing a search,
 * allowing you to inject additional actions or to remove existing actions.
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_searchTasks
 *
 * @param string $objectType
 * @param array $tasks
 */
function dedupetools_civicrm_searchTasks($objectType, &$tasks) {
  deduper_civicrm_searchTasks($objectType, $tasks);
}

/**
 * Implements hook_civicrm_preProcess().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_preProcess
 */
function dedupetools_civicrm_preProcess($formName, &$form) {
  deduper_civicrm_preProcess($formName, $form);
}

/**
 * Implements hook_civicrm_navigationMenu().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_navigationMenu
 */
function dedupetools_civicrm_navigationMenu(&$menu) {
  deduper_civicrm_navigationMenu($menu);
}

/**
 * Do not require administer CiviCRM to use deduper.
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_alterAPIPermissions
 *
 * @param string $entity
 * @param string $action
 * @param array $params
 * @param array $permissions
 */
function dedupetools_civicrm_alterAPIPermissions($entity, $action, &$params, &$permissions) {
  deduper_civicrm_alterAPIPermissions($entity, $action, $params, $permissions);
}

/**
 * Implementation of hook_civicrm_merge().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_merge
 *
 * @param string $type
 * @param array $refs
 * @param int $mainId
 * @param int $otherId
 * @param array $tables
 */
function dedupetools_civicrm_merge($type, &$refs, $mainId, $otherId, $tables) {
  deduper_civicrm_merge($type, $refs, $mainId, $otherId, $tables);
}

/**
 * Alter location data 'planned' for merge.
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_alterLocationMergeData
 *
 * @param array $blocksDAO
 *   Array of location DAO to be saved. These are arrays in 2 keys 'update' &
 *   'delete'.
 * @param int $mainId
 *   Contact_id of the contact that survives the merge.
 * @param int $otherId
 *   Contact_id of the contact that will be absorbed and deleted.
 * @param array $migrationInfo
 *   Calculated migration info, informational only.
 */
function dedupetools_civicrm_alterLocationMergeData(&$blocksDAO, $mainId, $otherId, $migrationInfo) {
  deduper_civicrm_alterLocationMergeData($blocksDAO, $mainId, $otherId, $migrationInfo);
}

/**
 * Set up a cache for saving dedupe pairs in.
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_container
 *
 * We want to be able to save dedupe name match info into an efficient cache - this equates to
 * caching in php for the duration of a process & Redis / MemCache (if available) for longer.
 *
 * Using 'withArray' => 'fast' means that if we access a value from Redis it's help in a php
 * cache for the rest of the process - so if we look up 'Tom' a lot we will usually be able to use
 * memory, sometimes Redis & rarely have to look up the table. If Redis is not available we will
 * use mysql to look up the table more.
 *
 * In latest CiviCRM there is a pre-defined 'metadata' cache with similar definitions & in the
 * future we will consider switching to it.
 *
 * https://docs.civicrm.org/dev/en/latest/framework/cache/
 *
 * @param \Symfony\Component\DependencyInjection\ContainerBuilder $container
 */
function dedupetools_civicrm_container($container) {
  deduper_civicrm_container($container);
}

/**
 * Implements hook_civicrm_entityTypes().
 *
 * Declare entity types provided by this module.
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_entityTypes
 */
function dedupetools_civicrm_entityTypes(&$entityTypes) {
  deduper_civicrm_entityTypes($entityTypes);
}

/**
 * Implements hook_civicrm_themes().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_themes
 */
function dedupetools_civicrm_themes(&$themes) {
  deduper_civicrm_themes($themes);
}

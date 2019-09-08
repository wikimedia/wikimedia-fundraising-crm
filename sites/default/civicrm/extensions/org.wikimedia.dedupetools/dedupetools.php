<?php

require_once 'dedupetools.civix.php';

/**
 * Implements hook_civicrm_config().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_config
 */
function dedupetools_civicrm_config(&$config) {
  _dedupetools_civix_civicrm_config($config);
}

/**
 * Implements hook_civicrm_xmlMenu().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_xmlMenu
 */
function dedupetools_civicrm_xmlMenu(&$files) {
  _dedupetools_civix_civicrm_xmlMenu($files);
}

/**
 * Implements hook_civicrm_install().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_install
 */
function dedupetools_civicrm_install() {
  _dedupetools_civix_civicrm_install();
}

/**
 * Implements hook_civicrm_postInstall().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_postInstall
 */
function dedupetools_civicrm_postInstall() {
  _dedupetools_civix_civicrm_postInstall();
}

/**
 * Implements hook_civicrm_uninstall().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_uninstall
 */
function dedupetools_civicrm_uninstall() {
  _dedupetools_civix_civicrm_uninstall();
}

/**
 * Implements hook_civicrm_enable().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_enable
 */
function dedupetools_civicrm_enable() {
  _dedupetools_civix_civicrm_enable();
}

/**
 * Implements hook_civicrm_disable().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_disable
 */
function dedupetools_civicrm_disable() {
  _dedupetools_civix_civicrm_disable();
}

/**
 * Implements hook_civicrm_upgrade().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_upgrade
 */
function dedupetools_civicrm_upgrade($op, CRM_Queue_Queue $queue = NULL) {
  return _dedupetools_civix_civicrm_upgrade($op, $queue);
}

/**
 * Implements hook_civicrm_managed().
 *
 * Generate a list of entities to create/deactivate/delete when this module
 * is installed, disabled, uninstalled.
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_managed
 */
function dedupetools_civicrm_managed(&$entities) {
  _dedupetools_civix_civicrm_managed($entities);
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
function dedupetools_civicrm_caseTypes(&$caseTypes) {
  _dedupetools_civix_civicrm_caseTypes($caseTypes);
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
function dedupetools_civicrm_angularModules(&$angularModules) {
  _dedupetools_civix_civicrm_angularModules($angularModules);
  $angularModules['xeditable'] = [
    'ext' => 'org.wikimedia.dedupetools',
    'js' => ['bower_components/angular-xeditable/dist/js/xeditable.js'],
    'css' => ['bower_components/angular-xeditable/dist/css/xeditable.css'],
  ];
  $angularModules['angularUtils.directives.dirPagination'] = [
    'ext' => 'org.wikimedia.dedupetools',
    'js' => ['bower_components/angularUtils-pagination/dirPagination.js'],
  ];

}

/**
 * Implements hook_civicrm_alterSettingsFolders().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_alterSettingsFolders
 */
function dedupetools_civicrm_alterSettingsFolders(&$metaDataFolders = NULL) {
  _dedupetools_civix_civicrm_alterSettingsFolders($metaDataFolders);
}

/**
 * Add dedupe searches to actions available.
 *
 * @param array $actions
 * @param int $contactID
 */
function dedupetools_civicrm_summaryActions(&$actions, $contactID) {
  if ($contactID === NULL) {
    return;
  }
  try {
    $ruleGroups = civicrm_api3('RuleGroup', 'get', array(
      'contact_type' => civicrm_api3('Contact', 'getvalue' , array('id' => $contactID, 'return' => 'contact_type')),
    ));
    $weight = 500;

    $contactIDS = array($contactID);
    foreach ($ruleGroups['values'] as $ruleGroup) {
      $actions['otherActions']['dupe' . $ruleGroup['id']] = array(
        'title' => ts('Find matches using Rule : %1', array(1 => $ruleGroup['title'])),
        'name' => ts('Find matches using Rule : %1', array(1 => $ruleGroup['title'])),
        'weight' => $weight,
        'ref' => 'dupe-rule crm-contact_activities-list',
        'key' => 'dupe' . $ruleGroup['id'],
        'href' => CRM_Utils_System::url('civicrm/contact/dedupefind', array(
          'reset' => 1,
          'action' => 'update',
          'rgid' => $ruleGroup['id'],
          'criteria' => json_encode(array('contact' => array('id' => array('IN' => $contactIDS)))),
          'limit' => count($contactIDS),
        )),
      );
      $weight++;
    }
  }
  catch (CiviCRM_API3_Exception $e) {
    // This would most likely happen if viewing a deleted contact since we are not forcing
    // them to be returned. Keep calm & carry on.
  }
}

/**
 * Keep merge conflict analysis out of log tables. It is temporary data.
 *
 * @param array $logTableSpec
 */
function dedupetools_civicrm_alterLogTables(&$logTableSpec) {
  unset($logTableSpec['civicrm_merge_conflict']);
}

/**
 * This hook is called to display the list of actions allowed after doing a search,
 * allowing you to inject additional actions or to remove existing actions.
 *
 * @param string $objectType
 * @param array $tasks
 */
function dedupetools_civicrm_searchTasks($objectType, &$tasks) {
  if ($objectType === 'contact') {
    $tasks[] = [
      'title' => ts('Find duplicates for these contacts'),
      'class' => 'CRM_Contact_Form_Task_FindDuplicates',
      'result' => TRUE,
    ];

  }
}

/**
 * Implements hook_civicrm_preProcess().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_preProcess
 */
function dedupetools_civicrm_preProcess($formName, &$form) {
  if ($formName === 'CRM_Contact_Form_Merge') {
    // Re-add colour coding - sill not be required when issue is resolved.
    // https://github.com/civicrm/org.civicrm.shoreditch/issues/373
    CRM_Core_Resources::singleton()->addStyle('
    /* table row highlightng */
    .page-civicrm-contact-merge .crm-container table.row-highlight tr.crm-row-ok td{
       background-color: #EFFFE7 !important;
    }
    .page-civicrm-contact-merge .crm-container table.row-highlight .crm-row-error td{
       background-color: #FFECEC !important;
    }');
  }
}

/**
 * Implements hook_civicrm_navigationMenu().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_navigationMenu
 */
function dedupetools_civicrm_navigationMenu(&$menu) {
  _dedupetools_civix_insert_navigation_menu($menu, 'Contacts', [
    'label' => ts('Deduper', array('domain' => 'org.wikimedia.dedupetools')),
    'name' => 'deduper',
    'url' => 'civicrm/a/#/dupefinder/Contact',
    'permission' => 'access CiviCRM',
    'operator' => 'OR',
    'separator' => 0,
  ]);
  _dedupetools_civix_navigationMenu($menu);
}

/**
 * Do not require administer CiviCRM to use deduper.
 *
 * @param string $entity
 * @param string $action
 * @param array $params
 * @param array $permissions
 */
function dedupetools_civicrm_alterAPIPermissions($entity, $action, &$params, &$permissions) {
  // Set permission for all deduping actions to 'merge duplicate contacts'
  // We are still hoping to get something merged upstream for these 2.
  $permissions['merge'] = [
    'mark_duplicate_exception' => ['merge duplicate contacts'],
    'getcount' => ['merge duplicate contacts'],
  ];

  // This isn't really exposed at the moment but it would have the same perms if it were.
  // It would be to allow conflicts to be marked as skip-handlable.
  $permissions['merge_conflict'] = [
    'get' => ['merge duplicate contacts'],
    'create' => ['merge duplicate contacts'],
  ];


}

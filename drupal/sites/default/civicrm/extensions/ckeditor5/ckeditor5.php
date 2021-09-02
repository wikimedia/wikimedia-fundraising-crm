<?php

require_once 'ckeditor5.civix.php';
use CRM_Ckeditor5_ExtensionUtil as E;

/**
 * Implements hook_civicrm_config().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_config/
 */
function ckeditor5_civicrm_config(&$config) {
  _ckeditor5_civix_civicrm_config($config);
}

/**
 * Implements hook_civicrm_xmlMenu().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_xmlMenu
 */
function ckeditor5_civicrm_xmlMenu(&$files) {
  _ckeditor5_civix_civicrm_xmlMenu($files);
}

/**
 * Implements hook_civicrm_install().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_install
 */
function ckeditor5_civicrm_install() {
  _ckeditor5_civix_civicrm_install();
}

/**
 * Implements hook_civicrm_postInstall().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_postInstall
 */
function ckeditor5_civicrm_postInstall() {
  _ckeditor5_civix_civicrm_postInstall();
}

/**
 * Implements hook_civicrm_uninstall().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_uninstall
 */
function ckeditor5_civicrm_uninstall() {
  _ckeditor5_civix_civicrm_uninstall();
}

/**
 * Implements hook_civicrm_enable().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_enable
 */
function ckeditor5_civicrm_enable() {
  _ckeditor5_civix_civicrm_enable();
}

/**
 * Implements hook_civicrm_disable().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_disable
 */
function ckeditor5_civicrm_disable() {
  _ckeditor5_civix_civicrm_disable();
}

/**
 * Implements hook_civicrm_upgrade().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_upgrade
 */
function ckeditor5_civicrm_upgrade($op, CRM_Queue_Queue $queue = NULL) {
  return _ckeditor5_civix_civicrm_upgrade($op, $queue);
}

/**
 * Implements hook_civicrm_managed().
 *
 * Generate a list of entities to create/deactivate/delete when this module
 * is installed, disabled, uninstalled.
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_managed
 */
function ckeditor5_civicrm_managed(&$entities) {
  _ckeditor5_civix_civicrm_managed($entities);
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
function ckeditor5_civicrm_caseTypes(&$caseTypes) {
  _ckeditor5_civix_civicrm_caseTypes($caseTypes);
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
function ckeditor5_civicrm_angularModules(&$angularModules) {
  _ckeditor5_civix_civicrm_angularModules($angularModules);
}

/**
 * Implements hook_civicrm_alterSettingsFolders().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_alterSettingsFolders
 */
function ckeditor5_civicrm_alterSettingsFolders(&$metaDataFolders = NULL) {
  _ckeditor5_civix_civicrm_alterSettingsFolders($metaDataFolders);
}

/**
 * Implements hook_civicrm_entityTypes().
 *
 * Declare entity types provided by this module.
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_entityTypes
 */
function ckeditor5_civicrm_entityTypes(&$entityTypes) {
  _ckeditor5_civix_civicrm_entityTypes($entityTypes);
}

/**
 * Implements hook_civicrm_thems().
 */
function ckeditor5_civicrm_themes(&$themes) {
  _ckeditor5_civix_civicrm_themes($themes);
}

/**
 * Implements hook_civicrm_coreResourceList
 *
 * Add ckeditor5.
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_coreResourceList
 *
 * @param array $items
 * @param array $region
 */
function ckeditor5_civicrm_coreResourceList(&$items, $region) {
  if ($region === 'html-header') {
    if (Civi::settings()->get('editor_id') === 'CKEditor5-elfinder') {
      $items[] = [
        'config' => [
          'wysisygScriptLocation' => CRM_Core_Resources::singleton()->getUrl('ckeditor5', 'js/wysiwyg/crm.ckeditor5.js'),
          // Note that I am just using 'classic build' at the moment - not a configured
          // build so no build in the path.
          'CKEditor5Location' => CRM_Core_Resources::singleton()->getUrl('ckeditor5', 'js/ckeditor5/ckeditor-classic-build/ckeditor.js'),
          'ELFinderLocation' => CRM_Core_Resources::singleton()->getUrl('ckeditor5', 'js/elFinder/js/elfinder.min.js'),
          'ELFinderConnnector' => CRM_Utils_System::url('civicrm/image/access'),
        ],
      ];
      CRM_Core_Resources::singleton()->addStyleUrl(CRM_Core_Resources::singleton()->getUrl('ckeditor5', 'js/elFinder/css/elfinder.min.css'));
      CRM_Core_Resources::singleton()->addStyleUrl(CRM_Core_Resources::singleton()->getUrl('ckeditor5', 'css/ckeditor.css'));
    }

    if (Civi::settings()->get('editor_id') === 'CKEditor5-base64') {
      $items[] = [
        'config' => [
          'wysisygScriptLocation' => CRM_Core_Resources::singleton()->getUrl('ckeditor5', 'js/wysiwyg/crm.ckeditor5.js'),
          // Note that I am just using 'classic build' at the moment - not a configured
          // build so no build in the path.
          'CKEditor5Location' => CRM_Core_Resources::singleton()->getUrl('ckeditor5', 'js/ckeditor5/ckeditor-base64-upload-adapter/build/ckeditor.js'),
          'ELFinderLocation' => NULL,
          'ELFinderConnnector' => NULL,
        ],
      ];
      CRM_Core_Resources::singleton()->addStyleUrl(CRM_Core_Resources::singleton()->getUrl('ckeditor5', 'css/ckeditor.css'));
    }

  }
}

// --- Functions below this ship commented out. Uncomment as required. ---

/**
 * Implements hook_civicrm_preProcess().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_preProcess
 *
function ckeditor5_civicrm_preProcess($formName, &$form) {

} // */

/**
 * Implements hook_civicrm_navigationMenu().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_navigationMenu
 *
function ckeditor5_civicrm_navigationMenu(&$menu) {
  _ckeditor5_civix_insert_navigation_menu($menu, 'Mailings', array(
    'label' => E::ts('New subliminal message'),
    'name' => 'mailing_subliminal_message',
    'url' => 'civicrm/mailing/subliminal',
    'permission' => 'access CiviMail',
    'operator' => 'OR',
    'separator' => 0,
  ));
  _ckeditor5_civix_navigationMenu($menu);
} // */

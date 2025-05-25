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
 * Implements hook_civicrm_install().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_install
 */
function ckeditor5_civicrm_install() {
  _ckeditor5_civix_civicrm_install();
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
      $locale = CRM_Core_I18n::getLocale();
      $lang = substr($locale, 0, 2);

      $items[] = [
        'config' => [
          'CKEditor5Language' => ($lang != 'en' ? CRM_Core_Resources::singleton()->getUrl('ckeditor5', 'js/ckeditor5/ckeditor-classic-build/translations/' . $lang . '.js') : ''),
          'wysisygScriptLocation' => CRM_Core_Resources::singleton()->getUrl('ckeditor5', 'js/wysiwyg/crm.ckeditor5.js'),
          // Note that I am just using 'classic build' at the moment - not a configured
          // build so no build in the path.
          'CKEditor5Location' => CRM_Core_Resources::singleton()->getUrl('ckeditor5', 'js/ckeditor5/ckeditor-classic-build/ckeditor.js'),
          'ELFinderLocation' => CRM_Core_Resources::singleton()->getUrl('ckeditor5', 'js/elFinder/js/elfinder.min.js'),
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

 // */

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

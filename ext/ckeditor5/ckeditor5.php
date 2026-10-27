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
 * Implements hook_civicrm_alterSettingsFolders().
 */
function ckeditor5_civicrm_alterSettingsFolders(&$folders) {
  $folders[] = E::path('settings');
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
    if (in_array(Civi::settings()->get('editor_id'), ['CKEditor5-elfinder', 'CKEditor5-base64'], TRUE)) {
      $items[] = ['config' => [
        'CKEditor5AdminPresets' => (object) (Civi::settings()->get('ckeditor5_presets') ?: []),
        'CKEditor5CorePluginAssets' => [[
          'name' => 'civi-tokens',
          'scripts' => [CRM_Core_Resources::singleton()->getUrl('ckeditor5', 'js/plugins/civi-tokens.js', TRUE)],
          'plugins' => ['CiviTokens'],
        ]],
      ]];
    }
    if (Civi::settings()->get('editor_id') === 'CKEditor5-elfinder') {
      $locale = CRM_Core_I18n::getLocale();
      $lang = substr($locale, 0, 2);
      $canUseElFinder = CRM_Core_Permission::check('access CiviCRM')
        && CRM_Core_Permission::check('access uploaded files');

      $items[] = [
        'config' => [
          'CKEditor5Language' => ($lang != 'en' ? CRM_Core_Resources::singleton()->getUrl('ckeditor5', 'js/ckeditor5/ckeditor-classic-build/build/translations/' . $lang . '.js', TRUE) : ''),
          'wysisygScriptLocation' => CRM_Core_Resources::singleton()->getUrl('ckeditor5', 'js/wysiwyg/crm.ckeditor5.js', TRUE),
          // Both upload modes use the same compiled editor.
          'CKEditor5Location' => CRM_Core_Resources::singleton()->getUrl('ckeditor5', 'js/ckeditor5/ckeditor-classic-build/build/ckeditor.js', TRUE),
          'ELFinderLocation' => $canUseElFinder ? CRM_Core_Resources::singleton()->getUrl('ckeditor5', 'js/elFinder/js/elfinder.min.js', TRUE) : NULL,
          'CKEditor5UploadMode' => $canUseElFinder ? 'elfinder' : 'none',
        ],
      ];
      if ($canUseElFinder) {
        CRM_Core_Resources::singleton()->addStyleUrl(CRM_Core_Resources::singleton()->getUrl('ckeditor5', 'js/elFinder/css/elfinder.min.css', TRUE));
        CRM_Core_Resources::singleton()->addStyleUrl(CRM_Core_Resources::singleton()->getUrl('ckeditor5', 'js/elFinder/css/theme.css', TRUE));
      }
      CRM_Core_Resources::singleton()->addStyleUrl(CRM_Core_Resources::singleton()->getUrl('ckeditor5', 'css/ckeditor.css', TRUE));
    }

    if (Civi::settings()->get('editor_id') === 'CKEditor5-base64') {
      $locale = CRM_Core_I18n::getLocale();
      $lang = substr($locale, 0, 2);

      $items[] = [
        'config' => [
          'CKEditor5Language' => ($lang != 'en' ? CRM_Core_Resources::singleton()->getUrl('ckeditor5', 'js/ckeditor5/ckeditor-classic-build/build/translations/' . $lang . '.js', TRUE) : ''),
          'wysisygScriptLocation' => CRM_Core_Resources::singleton()->getUrl('ckeditor5', 'js/wysiwyg/crm.ckeditor5.js', TRUE),
          // Both upload modes use the same compiled editor.
          'CKEditor5Location' => CRM_Core_Resources::singleton()->getUrl('ckeditor5', 'js/ckeditor5/ckeditor-classic-build/build/ckeditor.js', TRUE),
          'ELFinderLocation' => NULL,
          'CKEditor5UploadMode' => 'base64',
        ],
      ];
      CRM_Core_Resources::singleton()->addStyleUrl(CRM_Core_Resources::singleton()->getUrl('ckeditor5', 'css/ckeditor.css', TRUE));
    }

  }
}

/**
 * Implements hook_civicrm_navigationMenu().
 */
function ckeditor5_civicrm_navigationMenu(&$menu) {
  _ckeditor5_civix_insert_navigation_menu($menu, 'Administer/Customize Data and Screens', [
    'label' => E::ts('CKEditor 5 presets'),
    'name' => 'ckeditor5_presets',
    'url' => 'civicrm/admin/ckeditor5?reset=1',
    'permission' => 'administer CiviCRM',
  ]);
}


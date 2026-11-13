<?php

require_once 'ckeditor5.civix.php';
use CRM_Ckeditor5_ExtensionUtil as E;

const CKEDITOR5_PERMISSION_UPLOAD_IMAGES = 'upload CKEditor 5 images';

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
 * Implements hook_civicrm_permission().
 */
function ckeditor5_civicrm_permission(array &$permissions): void {
  $permissions[CKEDITOR5_PERMISSION_UPLOAD_IMAGES] = [
    'label' => E::ts('CKEditor 5: upload images'),
    'description' => E::ts('Upload images directly from CKEditor 5. This does not grant access to browse existing uploaded files.'),
    'implied_by' => ['administer CiviCRM'],
  ];
}

/**
 * Implements hook_civicrm_buildForm().
 */
function ckeditor5_civicrm_buildForm($formName, &$form) {
  if ($formName !== 'CRM_Admin_Form_Preferences_Display') {
    return;
  }
  $form->addElement(
    'xbutton',
    'ckeditor5_config',
    CRM_Core_Page::crmIcon('fa-wrench') . ' ' . E::ts('Configure CKEditor 5'),
    [
      'type' => 'submit',
      'class' => 'crm-button',
      'style' => 'display:inline-flex;align-items:center;justify-content:center;gap:0.4em;vertical-align:middle;float:none!important;white-space:nowrap;',
      'value' => 1,
    ]
  );
  CRM_Core_Region::instance('form-bottom')->add([
    'template' => 'CRM/Admin/Form/Preferences/Ckeditor5.tpl',
  ]);
}

/**
 * Implements hook_civicrm_postProcess().
 */
function ckeditor5_civicrm_postProcess($formName, &$form) {
  if ($formName !== 'CRM_Admin_Form_Preferences_Display' || empty($form->_submitValues['ckeditor5_config'])) {
    return;
  }
  $session = CRM_Core_Session::singleton();
  $session->getStatus(TRUE);
  $session->pushUserContext(CRM_Utils_System::url('civicrm/admin/ckeditor5', 'reset=1'));
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
  if ($region !== 'html-header') {
    return;
  }

  $editorId = Civi::settings()->get('editor_id');
  if (!CRM_Ckeditor5_EditorRegistration::supports($editorId)) {
    return;
  }

  $legacyUploadMode = CRM_Ckeditor5_EditorRegistration::legacyUploadMode($editorId);
  $presets = Civi::settings()->get('ckeditor5_presets') ?: [];

  $locale = CRM_Core_I18n::getLocale();
  $lang = substr($locale, 0, 2);
  $canAccessCivi = CRM_Core_Permission::check('access CiviCRM');
  $canUpload = $canAccessCivi && CRM_Core_Permission::check(CKEDITOR5_PERMISSION_UPLOAD_IMAGES);
  $canBrowse = $canAccessCivi && CRM_Core_Permission::check('access uploaded files');
  $canUseElFinder = $canBrowse && $legacyUploadMode !== 'base64';
  $uploadMode = $canUpload ? ($legacyUploadMode ?: 'elfinder') : 'none';
  $resources = CRM_Core_Resources::singleton();

  $items[] = ['config' => [
    'CKEditor5AdminPresets' => (object) $presets,
    'CKEditor5CorePluginAssets' => [[
      'name' => 'civi-tokens',
      'scripts' => [$resources->getUrl('ckeditor5', 'js/plugins/civi-tokens.js', TRUE)],
      'plugins' => ['CiviTokens'],
    ]],
    'CKEditor5Language' => ($lang !== 'en' ? $resources->getUrl('ckeditor5', 'js/ckeditor5/ckeditor-classic-build/build/translations/' . $lang . '.js', TRUE) : ''),
    'wysisygScriptLocation' => $resources->getUrl('ckeditor5', 'js/wysiwyg/crm.ckeditor5.js', TRUE),
    'CKEditor5Location' => $resources->getUrl('ckeditor5', 'js/ckeditor5/ckeditor-classic-build/build/ckeditor.js', TRUE),
    'CKEditor5EmojiDefinitions' => $resources->getUrl('ckeditor5', 'js/ckeditor5/emoji/16/en.json', TRUE),
    'ELFinderLocation' => $canUseElFinder ? $resources->getUrl('ckeditor5', 'js/elFinder/js/elfinder.min.js', TRUE) : NULL,
    'CKEditor5Stylesheets' => [
      $resources->getUrl('ckeditor5', 'css/ckeditor.css', TRUE),
    ],
    'ELFinderStylesheets' => $canUseElFinder ? [
      $resources->getUrl('ckeditor5', 'js/elFinder/css/elfinder.min.css', TRUE),
      $resources->getUrl('ckeditor5', 'js/elFinder/css/theme.css', TRUE),
      $resources->getUrl('ckeditor5', 'css/elfinder-light.css', TRUE),
    ] : [],
    'CKEditor5UploadMode' => $uploadMode,
    'CKEditor5CanUploadFiles' => $canUpload,
    'CKEditor5CanBrowseFiles' => $canBrowse,
  ]];

  if ($canUseElFinder) {
    $resources->addStyleUrl($resources->getUrl('ckeditor5', 'js/elFinder/css/elfinder.min.css', TRUE));
    $resources->addStyleUrl($resources->getUrl('ckeditor5', 'js/elFinder/css/theme.css', TRUE));
    $resources->addStyleUrl($resources->getUrl('ckeditor5', 'css/elfinder-light.css', TRUE));
  }
  $resources->addStyleUrl($resources->getUrl('ckeditor5', 'css/ckeditor.css', TRUE));
}


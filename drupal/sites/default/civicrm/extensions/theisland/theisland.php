<?php

require_once 'theisland.civix.php';
// phpcs:disable
use CRM_Theisland_ExtensionUtil as E;
// phpcs:enable

/**
 * Implements hook_civicrm_config().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_config/
 */
function theisland_civicrm_config(&$config): void {
  _theisland_civix_civicrm_config($config);

  if (CIVICRM_UF == 'WordPress') {
    if (empty(CRM_Utils_Request::retrieveValue('snippet', 'String'))) {
      // Inline the CSS so that the page load is less glitchy
      // See also shoreditchwpworkarounds_body_class()
      $css = file_get_contents(E::path('css/wordpress.css'));
      Civi::resources()->addStyle($css);
    }
  }
}

/**
 * Implements hook_civicrm_install().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_install
 */
function theisland_civicrm_install(): void {
  _theisland_civix_civicrm_install();
}

/**
 * Implements hook_civicrm_enable().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_enable
 */
function theisland_civicrm_enable(): void {
  _theisland_civix_civicrm_enable();
}

/**
 * Implements hook_civicrm_coreResourceList().
 */
function theisland_civicrm_coreResourceList(&$items, $region) {
  if (!_theisland_isActive()) {
    return;
  }

  if ($region == 'html-header') {
    Civi::resources()->addStyleFile('theisland', 'css/bootstrap.css', -50, 'html-header');
    Civi::resources()->addStyleFile('theisland', 'css/custom-civicrm.css', 99, 'html-header');
    Civi::resources()->addScriptFile('theisland', 'base/js/transition.js', 1000, 'html-header');
    Civi::resources()->addScriptFile('theisland', 'base/js/scrollspy.js', 1000, 'html-header');
    Civi::resources()->addScriptFile('theisland', 'base/js/dropdown.js', 1000, 'html-header');
    Civi::resources()->addScriptFile('theisland', 'base/js/collapse.js', 1000, 'html-header');
    Civi::resources()->addScriptFile('theisland', 'base/js/modal.js', 1000, 'html-header');
    Civi::resources()->addScriptFile('theisland', 'base/js/tab.js', 1000, 'html-header');
    Civi::resources()->addScriptFile('theisland', 'base/js/button.js', 1000, 'html-header');
    Civi::resources()->addScriptFile('theisland', 'js/noConflict.js', 1001, 'html-header');
    Civi::resources()->addScriptFile('theisland', 'js/add-missing-date-addons.js');
    Civi::resources()->addScriptFile('theisland', 'js/jquery-ui-popup-overrides.js');
  }
}

/**
 * Implements hook_civicrm_alterContent()
 */
function theisland_civicrm_alterContent(&$content, $context, $tplName, &$object) {
  if (CIVICRM_UF == 'Standalone') {
    // Emulate Drupal7 behaviour by adding the page-civicrm-xx classes on the body
    $classes = ['page-civicrm'];
    $path = CRM_Utils_System::currentPath();
    $parts = explode('/', $path);

    while (count($parts) > 1) {
      $classes[] = 'page-' . implode('-', $parts);
      array_pop($parts);
    }

    $content = str_replace('<body>', '<body class="' . implode(' ', $classes) . '">', $content);
  }
}

/**
 * Implements hook_civicrm_buildForm().
 */
function theisland_civicrm_buildForm($formName) {
  if ($formName == 'CRM_Contact_Form_Search_Advanced') {
    Civi::resources()->addScriptFile('theisland', 'js/highlight-table-rows.js');
  }
}

/**
 * Implements hook_civicrm_pageRun().
 */
function theisland_civicrm_pageRun(&$page) {
  $pageName = $page->getVar('_name');

  if ($pageName == 'CRM_Contact_Page_View_Summary') {
    Civi::resources()->addScriptFile('theisland', 'js/contact-summary.js');
  }
}

/**
 * Implements hook_civicrm_navigationMenu().
 */
function theisland_civicrm_navigationMenu(&$menu) {
  _theisland_civix_insert_navigation_menu($menu, 'Administer/Customize Data and Screens', [
    'label' => E::ts('The Island'),
    'name' => 'theisland-settings',
    'url' => 'civicrm/admin/setting/theisland',
    'permission' => 'Administer CiviCRM',
  ]);
}

/**
 * @return bool
 *   TRUE if The Island is the active theme.
 */
function _theisland_isActive() {
  // The Job civicrm_api3_job_cleanup can clear the DB cache, causing the
  // CiviCRM menu to be empty on the first load after the command ran.
  // Without the menu, the getActiveThemeKey is unable to detect the current
  // theme. This condition ensures the menu is filled.
  if (!CRM_Core_DAO::checkTableHasData(CRM_Core_DAO_Menu::getTableName())) {
    CRM_Core_Menu::store(FALSE);
  }

  return Civi::service('themes')->getActiveThemeKey() === 'theisland';
}

// Fix WordPress - copied from shoreditchwpworkarounds
// To avoid duplication and be upgrade-friendly, check if it is still enabled
// but eventually add a System Check instead
if (function_exists('add_filter') && !function_exists('shoreditchwpworkarounds_body_class')) {
  add_filter('admin_body_class', 'theisland_body_class');
}

/**
 * Adds one or more classes to the body tag in the dashboard.
 *
 * @link https://wordpress.stackexchange.com/a/154951/17187
 * @param  String $classes Current body classes.
 * @return String          Altered body classes.
 */
function theisland_body_class($classes) {
  civicrm_initialize();
  $path = CRM_Utils_System::currentPath();
  $item = CRM_Core_Menu::get($path);

  if (!empty($item) && empty(CRM_Utils_Request::retrieveValue('snippet', 'String'))) {
    $items = explode('/', $item['path']);
    $cnt = count($items);

    $newclasses = [
      'page-civicrm',
      'page-' . implode('-', array_slice($items, 0, 2)),
      'page-' . implode('-', array_slice($items, 0, 3)),
    ];

    return "$classes " . implode(' ', $newclasses);
  }

  return "$classes";
}
<?php

require_once 'rpow.civix.php';
use CRM_Rpow_ExtensionUtil as E;

/**
 * Initialize civirpow state.
 *
 * @param array $config
 *   Ex: [
 *    'slaves' => ['mysql://ro_user:ro_pass@ro_host/ro_db?new_link=true'],
 *    'masters' => ['mysql://rw_user:rw_pass@rw_host/rw_db?new_link=true'],
 *    'cookieSigningKey' => 'asdf12344321fdsa',
 *   ])
 */
function rpow_init($config = []) {
  $defaultCookieSigningKey = md5(json_encode([
    $_SERVER['HTTP_HOST'] ?? NULL,
    $config['masters'],
    $config['slaves'],
    $_SERVER['HTTP_HOST'] ?? NULL,
  ]));
  $defaults = [
    'onReconnect' => [
      '_rpow_update_cookie',
    ],
    'cookieSigningKey' => $defaultCookieSigningKey,
    'cookieName' => 'rpow' . substr(md5('cookie::' . $defaultCookieSigningKey), 0, 8),
    'cookieTtl' => 90,
    'stateMachine' => new CRM_Rpow_StateMachine(),
    'debug' => 1,
  ];

  global $civirpow;
  $civirpow = array_merge($defaults, $civirpow ?: [], $config);

  // FIXME: cookie expires relative to first edit; should be relative to last edit
  if (_rpow_has_cookie($civirpow)) {
    $civirpow['forceWrite'] = 1;
  }

  define('CIVICRM_DSN', 'civirpow://');
  switch (getenv('RPOW') ?: '') {
    case 'ro':
    case 'slave':
      define('CIVICRM_CLI_DSN', $config['slaves'][0] ?? $config['masters'][0]);
      break;
    case 'rw':
    case 'master':
    case '':
      define('CIVICRM_CLI_DSN', $config['masters'][0]);
      break;

    default:
      throw new \Exception("Unrecognized RPOW");
  }

  // define('CIVICRM_DSN', $config['masters'][0]);
  // define('CIVICRM_DSN', $config['slaves'][0]);
}

function _rpow_signer($config) {
  return new \CRM_Utils_Signer($config['cookieSigningKey'], ['exp']);
}

function _rpow_has_cookie($config) {
  if (isset($_COOKIE[$config['cookieName']])) {
    $cookie = json_decode($_COOKIE[$config['cookieName']], TRUE);
  }
  else {
    $cookie = NULL;
  }

  if (isset($cookie['exp']) && $cookie['exp'] > time() && _rpow_signer($config)->validate($cookie['sig'], $cookie)) {
    return TRUE;
  }
  else {
    return FALSE;
  }
}

function _rpow_update_cookie($config, $db) {
  $signer = _rpow_signer($config);
  $expires = time() + $config['cookieTtl'];
  $buffer = '';
  if ($config['debug']) {
    foreach ($config['stateMachine']->getBuffer() as $i => $line) {
      $buffer .= "$i: $line\n";
    }
  }
  $value = json_encode([
    'exp' => $expires,
    'sig' => $signer->sign(['exp' => $expires]),
    'cause' => $buffer,
  ]);
  if (defined('CIVICRM_TEST') && CIVICRM_TEST) {
    // Return before setting a cookie in the test context as it can
    // cause test mischief - ie. https://github.com/totten/rpow/issues/8
    return;
  }
  setcookie($config['cookieName'], $value, $expires, '/');
}

// ----------------------------------------------------------------------------
// ----------------------------------------------------------------------------


/**
 * Implements hook_civicrm_config().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_config
 */
function rpow_civicrm_config(&$config) {
  _rpow_civix_civicrm_config($config);
}

/**
 * Implements hook_civicrm_install().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_install
 */
function rpow_civicrm_install() {
  _rpow_civix_civicrm_install();
}

/**
 * Implements hook_civicrm_enable().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_enable
 */
function rpow_civicrm_enable() {
  _rpow_civix_civicrm_enable();
}

// --- Functions below this ship commented out. Uncomment as required. ---

/**
 * Implements hook_civicrm_preProcess().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_preProcess
 *

 // */

/**
 * Implements hook_civicrm_navigationMenu().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_navigationMenu
 *
function rpow_civicrm_navigationMenu(&$menu) {
  _rpow_civix_insert_navigation_menu($menu, 'Mailings', array(
    'label' => E::ts('New subliminal message'),
    'name' => 'mailing_subliminal_message',
    'url' => 'civicrm/mailing/subliminal',
    'permission' => 'access CiviMail',
    'operator' => 'OR',
    'separator' => 0,
  ));
  _rpow_civix_navigationMenu($menu);
} // */

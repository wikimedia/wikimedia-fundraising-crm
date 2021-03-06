<?php


/**
 * Add any missing extensions from our master array.
 */
function wmf_install_add_missing_extensions() {
  civicrm_initialize();
  // We are looking for the best place to set this dir. Currently on live it is
  // set in civicrm.settings etc. That will override any attempt to set it here.
  civicrm_api3('setting', 'create', array('extensionsDir' => 'sites/default/civicrm/extensions'));
  civicrm_api3('extension', 'refresh', array());

  $extensionResult = civicrm_api3('Extension', 'get', [])['values'];
  $extensions = [];
  foreach ($extensionResult as $extension) {
    if ($extension['status'] === 'installed') {
      $extensions[] = $extension['key'];
    }
  }

  foreach (wmf_install_get_installed_extensions() as $key) {
    if (!in_array($key, $extensions)) {
      civicrm_api3('extension', 'install', array('key' => $key));
    }
  }
}

/**
 * Get an array of installed extensions.
 *
 * @return array
 */
function wmf_install_get_installed_extensions() {
  return [
    // all our other extensions are dependencies here
    // so wmf-civicrm is one ring to rule them all.
    'wmf-civicrm',
  ];
}

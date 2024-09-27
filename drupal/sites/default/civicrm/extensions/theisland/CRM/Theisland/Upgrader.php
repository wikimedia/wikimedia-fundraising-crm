<?php

use CRM_Theisland_ExtensionUtil as E;

/**
 * Collection of upgrade steps.
 */
class CRM_Theisland_Upgrader extends CRM_Extension_Upgrader_Base {

  /**
   * Called on extension install.
   */
  public function install() {
    $this->setTheIslandAsBackendTheme();
  }

  /**
   * Sets theisland as the backend theme only
   */
  public function setTheIslandAsBackendTheme() {
    civicrm_api3('setting', 'create', [
      'theme_frontend' => 'default',
      'theme_backend' => 'theisland'
    ]);

    // TheIsland looks better with a white menu
    // Admins can change the setting later (this was previously hardcoded in the CSS)
    Civi::settings()->set('menubar_color', '#ffffff');
  }

  /**
   * On WordPress, keep the previous default behaviour of hiding the menubar.
   *
   * @return TRUE on success
   * @throws Exception
   */
  public function upgrade_1001() {
    if (CIVICRM_UF == 'WordPress') {
      Civi::settings()->set('theisland_hide_wp_menubar', TRUE);
    }
    return TRUE;
  }

  /**
   * Keep the default behaviour prior to MR!14 which removed island-specific CSS
   * on the CiviCRM menu colour.
   *
   * @return TRUE on success
   * @throws Exception
   */
  public function upgrade_1002() {
    Civi::settings()->set('menubar_color', '#ffffff');
    return TRUE;
  }

}

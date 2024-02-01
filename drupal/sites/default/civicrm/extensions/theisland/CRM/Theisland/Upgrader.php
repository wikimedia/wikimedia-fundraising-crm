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
  }
}

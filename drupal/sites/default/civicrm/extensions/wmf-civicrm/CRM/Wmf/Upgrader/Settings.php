<?php

/**
 * Class CRM_Wmf_Upgrader_Settings adds settings required for wmf functionality.
 *
 * @package Civi\Wmf
 */
class CRM_Wmf_Upgrader_Settings {

  /**
   * Set settings required for wmf functionality and configuration.
   */
  public function setWmfSettings() {
    foreach ($this->getWMFSettings() as $key => $value) {
      Civi::settings()->set($key, $value);
    }
  }

  /**
   * Get settings required for wmf functionality and configuration.
   *
   * @return array
   */
  public function getWmfSettings(): array {
    return [
      'deduper_resolver_preferred_contact_resolution' => 'most_recent_contributor',
    ];
  }

}

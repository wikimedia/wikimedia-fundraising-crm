<?php
namespace Civi\Api4\Action\WMFConfig;


use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\Generic\Result;

/**
 * Class SyncGeocodes
 *
 * Ensure that our US zip geocoder (only) is enabled.
 *
 * @package Civi\Api4
 */
class SyncGeocoders extends AbstractAction {

  /**
   * Ensure that our US zip geocoder (only) is enabled.
   *
   * @inheritDoc
   *
   * @param \Civi\Api4\Generic\Result $result
   *
   * @throws \CRM_Core_Exception
   */
  public function _run(Result $result): void {
    $geocoders = civicrm_api3('Geocoder', 'get', []);
    foreach ($geocoders['values'] as $geocoder) {
      if ($geocoder['name'] !== 'us_zip_geocoder') {
        civicrm_api3('Geocoder', 'create', [
          'id' => $geocoder['id'],
          'is_active' => 0,
        ]);
      }
      else {
        civicrm_api3('Geocoder', 'create', [
          'id' => $geocoder['id'],
          'is_active' => 1,
        ]);
      }
    }
    $result[] = [];

  }

}

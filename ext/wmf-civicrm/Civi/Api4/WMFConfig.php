<?php
namespace Civi\Api4;

use Civi\Api4\Action\WMFConfig\SyncCustomFields;
use Civi\Api4\Action\WMFConfig\SyncGeocoders;
use Civi\Api4\Generic\BasicGetFieldsAction;

/**
 * Class WMF Configuration management.
 *
 * Api entity for WMF specific configuration.
 *
 * @package Civi\Api4
 */
class WMFConfig extends Generic\AbstractEntity {

  /**
   * Ensure our WMF defined custom data fields have been added.
   *
   * @param bool $checkPermissions
   *
   * @return \Civi\Api4\Action\WMFConfig\SyncCustomFields
   */
  public static function syncCustomFields(bool $checkPermissions = TRUE): SyncCustomFields {
    return (new SyncCustomFields(__CLASS__, __FUNCTION__))
      ->setCheckPermissions($checkPermissions);
  }

  /**
   * Ensure our WMF defined custom geocode config is set up.
   *
   * @param bool $checkPermissions
   *
   * @return \Civi\Api4\Action\WMFConfig\SyncGeocoders
   */
  public static function syncGeocoders(bool $checkPermissions = TRUE): SyncGeocoders {
    return (new SyncGeocoders(__CLASS__, __FUNCTION__))
      ->setCheckPermissions($checkPermissions);
  }

  public static function getFields(bool $checkPermissions = TRUE): BasicGetFieldsAction {
    return (new BasicGetFieldsAction(__CLASS__, __FUNCTION__, function() {
      return [];
    }))->setCheckPermissions($checkPermissions);
  }

}

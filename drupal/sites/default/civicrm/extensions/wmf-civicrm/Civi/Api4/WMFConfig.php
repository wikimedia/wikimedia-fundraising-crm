<?php
namespace Civi\Api4;

use Civi\Api4\Action\WMFConfig\SyncCustomFields;

/**
 * Class WMF Configuration management.
 *
 * Api entity for WMF specific configuration.
 *
 * @package Civi\Api4
 */
class WMFConfig extends Generic\AbstractEntity {

  /**
   * Save a contact from wmf 'msg' formatted array.
   *
   * @param bool $checkPermissions
   *
   * @return \Civi\Api4\Action\WMFConfig\SyncCustomFields
   */
  public static function syncCustomFields(bool $checkPermissions = TRUE): SyncCustomFields {
    return (new SyncCustomFields(__CLASS__, __FUNCTION__))
      ->setCheckPermissions($checkPermissions);
  }

  public static function getFields() {}

}

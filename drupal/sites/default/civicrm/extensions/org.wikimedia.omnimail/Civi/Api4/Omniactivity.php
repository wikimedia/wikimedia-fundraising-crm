<?php
namespace Civi\Api4;

use Civi\Api4\Action\Omniactivity\Get;
use Civi\Api4\Action\Omniactivity\Load;
use Civi\Api4\Generic\BasicGetFieldsAction;

/**
 * Omniactivity action.
 *
 * Provided by the Omnimail for CiviCRM extension.
 *
 * @package Civi\Api4
 */
class Omniactivity extends Generic\AbstractEntity {

  /**
   * Omniactivity get.
   *
   * Create the group at the external provider.
   *
   * @param bool $checkPermissions
   *
   * @return \Civi\Api4\Action\Omniactivity\Get
   */
  public static function get(bool $checkPermissions = TRUE): Get {
    return (new Get(__CLASS__, __FUNCTION__))
      ->setCheckPermissions($checkPermissions);
  }

  /**
   * Omniactivity load.
   *
   * Create the group at the external provider.
   *
   * @param bool $checkPermissions
   *
   * @return \Civi\Api4\Action\Omniactivity\Load
   */
  public static function load(bool $checkPermissions = TRUE): Load {
    return (new Load(__CLASS__, __FUNCTION__))
      ->setCheckPermissions($checkPermissions);
  }

  /**
   * Get permissions.
   *
   * It may be that we don't need a permission check on this api at all at there is a check on the entity
   * retrieved.
   *
   * @return array
   */
  public static function permissions():array {
    return ['check' => 'administer CiviCRM'];
  }

  /**
   * @return \Civi\Api4\Generic\BasicGetFieldsAction
   */
  public static function getFields(): BasicGetFieldsAction {
    return new BasicGetFieldsAction(__CLASS__, __FUNCTION__, function () {
      return [];
    });
  }

}

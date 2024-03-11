<?php

namespace Civi\Api4;

use Civi\Api4\Action\RecurUpgradeEmail\Render;
use Civi\Api4\Action\RecurUpgradeEmail\Send;
use Civi\Api4\Generic\BasicGetFieldsAction;

/**
 * @package Civi\Api4
 */
class RecurUpgradeEmail extends Generic\AbstractEntity {

  /**
   * @param bool $checkPermissions
   *
   * @return \Civi\Api4\Action\RecurUpgradeEmail\Render
   */
  public static function render(bool $checkPermissions = TRUE): Action\RecurUpgradeEmail\Render {
    return (new Render(__CLASS__, __FUNCTION__))
      ->setCheckPermissions($checkPermissions);
  }

  /**
   * @param bool $checkPermissions
   *
   * @return \Civi\Api4\Action\RecurUpgradeEmail\Render
   */
  public static function send(bool $checkPermissions = TRUE): Action\RecurUpgradeEmail\Send {
    return (new Send(__CLASS__, __FUNCTION__))
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
    return ['render' => 'access CiviCRM', 'send' => 'access CiviCRM'];
  }

  /**
   * @return \Civi\Api4\Generic\BasicGetFieldsAction
   */
  public static function getFields(): BasicGetFieldsAction {
    return new BasicGetFieldsAction(__CLASS__, __FUNCTION__, function() {
      return [];
    });
  }

}

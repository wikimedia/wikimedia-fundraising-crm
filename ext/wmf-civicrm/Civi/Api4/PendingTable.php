<?php
namespace Civi\Api4;

use Civi\Api4\Action\PendingTable\Consume;
use Civi\Api4\Generic\BasicGetFieldsAction;

/**
 * Class PendingTable
 *
 * Api entity for SmashPig pending table manipulation
 *
 * @package Civi\Api4
 */
class PendingTable extends Generic\AbstractEntity {

  /**
   * Consume and rectify pending table messages
   *
   * @param bool $checkPermissions
   *
   * @return \Civi\Api4\Action\PendingTable\Consume
   *
   * @throws \CRM_Core_Exception
   */
  public static function consume(bool $checkPermissions = FALSE): Consume {
    return (new Consume(__CLASS__, __FUNCTION__))
      ->setCheckPermissions($checkPermissions);
  }

  public static function getFields(bool $checkPermissions = TRUE): BasicGetFieldsAction {
    return (new BasicGetFieldsAction(__CLASS__, __FUNCTION__, function() {
      return [];
    }))->setCheckPermissions($checkPermissions);
  }

}

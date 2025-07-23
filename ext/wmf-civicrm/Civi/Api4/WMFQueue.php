<?php

namespace Civi\Api4;

use Civi\Api4\Action\WMFQueue\Consume;
use Civi\Api4\Action\WMFQueue\ConsumeFile;
use Civi\Api4\Generic\BasicGetFieldsAction;

class WMFQueue extends Generic\AbstractEntity {

  /**
   * Consume messages from the upi-donations queue
   *
   * @param bool $checkPermissions
   *
   * @return \Civi\Api4\Action\WMFQueue\Consume
   *
   */
  public static function consume(bool $checkPermissions = FALSE): Consume {
    return (new Consume(__CLASS__, __FUNCTION__))
      ->setCheckPermissions($checkPermissions);
  }

  /**
   * Consume messages from the upi-donations queue
   *
   * @param bool $checkPermissions
   *
   * @return \Civi\Api4\Action\WMFQueue\ConsumeFile
   *
   */
  public static function consumeFile(bool $checkPermissions = FALSE): ConsumeFile {
    return (new ConsumeFile(__CLASS__, __FUNCTION__))
      ->setCheckPermissions($checkPermissions);
  }

  public static function getFields(bool $checkPermissions = TRUE): BasicGetFieldsAction {
    return (new BasicGetFieldsAction(__CLASS__, __FUNCTION__, function() {
      return [];
    }))->setCheckPermissions($checkPermissions);
  }

}

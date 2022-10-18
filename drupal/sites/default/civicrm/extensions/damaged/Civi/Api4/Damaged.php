<?php
namespace Civi\Api4;

use Civi\Api4\Action\Damaged\ResendToQueue;



class Damaged extends Generic\DAOEntity {

  /**
   * @param bool $checkPermissions
   *
   * @return ResendToQueue
   * @throws \CRM_Core_Exception
   */
  public static function resendToQueue(bool $checkPermissions = TRUE): ResendToQueue {
    return (new ResendToQueue(static::getEntityName(), __FUNCTION__))
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
  public static function permissions(): array {
    return ['resendToQueue' => 'administer CiviCRM'];
  }

}

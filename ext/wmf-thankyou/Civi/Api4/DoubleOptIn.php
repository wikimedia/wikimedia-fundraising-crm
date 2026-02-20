<?php

namespace Civi\Api4;

use Civi\Api4\Action\DoubleOptIn\Send;
use Civi\Api4\Generic\BasicGetFieldsAction;

/**
 * DoubleOptIn entity.
 *
 * Convenience entity for sending double opt-in emails
 *
 * @package Civi\Api4
 */
class DoubleOptIn extends Generic\AbstractEntity {

  /**
   * @param bool $checkPermissions
   *
   * @return \Civi\Api4\Action\DoubleOptIn\Send
   */
  public static function send(bool $checkPermissions = TRUE): Action\DoubleOptIn\Send {
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
    return ['send' => 'access CiviCRM'];
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

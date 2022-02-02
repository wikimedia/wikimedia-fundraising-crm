<?php

/*
 +--------------------------------------------------------------------+
 | Copyright CiviCRM LLC. All rights reserved.                        |
 |                                                                    |
 | This work is published under the GNU AGPLv3 license with some      |
 | permitted exceptions and without any warranty. For full license    |
 | and copyright information, see https://civicrm.org/licensing       |
 +--------------------------------------------------------------------+
 */

namespace Civi\Api4;

use Civi\Api4\Action\ThankYou\Render;
use Civi\Api4\Generic\BasicGetFieldsAction;

/**
 * MsgTemplate entity.
 *
 * This is a collection of MsgTemplate, for reuse in import, export, etc.
 *
 * @package Civi\Api4
 */
class ThankYou extends Generic\AbstractEntity {

  /**
   * @param bool $checkPermissions
   *
   * @return \Civi\Api4\Action\ThankYou\Render
   */
  public static function render(bool $checkPermissions = TRUE): Action\ThankYou\Render {
    return (new Render(__CLASS__, __FUNCTION__))
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

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

use Civi\Api4\Generic\BasicGetFieldsAction;

/**
 * MsgTemplate entity.
 *
 * This is a collection of MsgTemplate, for reuse in import, export, etc.
 *
 * @package Civi\Api4
 */
class Message extends Generic\AbstractEntity {

  /**
   * @return \Civi\Api4\Action\Message\Render
   *
   * @throws \API_Exception
   */
  public static function render() {
    return new Action\Message\Render(__CLASS__, __FUNCTION__);
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
    return ['render' => 'access CiviCRM'];
  }

  /**
   * @return \Civi\Api4\Generic\BasicGetFieldsAction
   */
  public static function getFields() {
    return new BasicGetFieldsAction(__CLASS__, __FUNCTION__, function() {
      return [];
    });
  }

}

<?php
namespace Civi\Api4;

use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\Generic\Result;
use Civi\Api4\Generic\BasicGetFieldsAction;

/**
*  Class OmnimailJobProgress.
*
* Provided by the  extension.
*
* @package Civi\Api4
*/
class OmnimailJobProgress extends Generic\DAOEntity {

  /**
   * OmnimailJobProgress Check.
   *
   * @return \Civi\Api4\Action\OmnimailJobProgress\Check   *
   * @param bool $checkPermissions
   *
   * @throws \CRM_Core_Exception
   */
  public static function check ($checkPermissions = TRUE): Action\OmnimailJobProgress\Check {
    return (new \Civi\Api4\Action\OmnimailJobProgress\Check(__CLASS__, __FUNCTION__))
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

}

<?php
namespace Civi\Api4;

use Civi\Api4\Action\Omnigroupmember\Load;
use Civi\Api4\Generic\BasicGetFieldsAction;
use Civi\Api4\Action\Omnigroup\Push;

/**
*  Class Omnigroup.
*
* Provided by the omnimail extension.
*
* @see https://developer.goacoustic.com/acoustic-campaign/reference/createcontactlist
* @package Civi\Api4
*/
class Omnigroupmember extends Generic\AbstractEntity {

  /**
   * Omnigroupmembership load.
   *
   * Load members of a remote group into CiviCRM..
   *
   * @param bool $checkPermissions
   *
   * @return \Civi\Api4\Action\Omnigroupmember\Load
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

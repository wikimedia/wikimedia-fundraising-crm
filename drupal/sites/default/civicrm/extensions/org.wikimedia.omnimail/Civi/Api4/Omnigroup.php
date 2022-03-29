<?php
namespace Civi\Api4;

use Civi\Api4\Generic\BasicGetFieldsAction;
use Civi\Api4\Action\Omnigroup\Create;
use Civi\Api4\Action\Omnigroup\Push;

/**
*  Class Omnigroup.
*
* Provided by the omnimail extension.
*
* @see https://developer.goacoustic.com/acoustic-campaign/reference/createcontactlist
* @package Civi\Api4
*/
class Omnigroup extends Generic\AbstractEntity {

  /**
   * Omnigroup create.
   *
   * Create the group at the external provider.
   *
   * @param bool $checkPermissions
   *
   * @return \Civi\Api4\Action\Omnigroup\Create
   */
  public static function create(bool $checkPermissions = TRUE): Create {
    return (new Create(__CLASS__, __FUNCTION__))
      ->setCheckPermissions($checkPermissions);
  }


  /**
   * Omnigroup Push.
   *
   * Push the group up to the external provider, including contacts.
   *
   * @param bool $checkPermissions
   *
   * @return \Civi\Api4\Action\Omnigroup\Push
   */
  public static function push(bool $checkPermissions = TRUE): Push {
    return (new Push(__CLASS__, __FUNCTION__))
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

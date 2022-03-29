<?php
namespace Civi\Api4;

use Civi\Api4\Generic\BasicGetFieldsAction;
use Civi\Api4\Action\Omnicontact\Create;
use Civi\Api4\Action\Omnicontact\Get;

/**
*  Class OmniContact.
*
* Provided by the omnimail extension.
*
* @see https://developer.goacoustic.com/acoustic-campaign/reference/add-a-contact
* @package Civi\Api4
*/
class Omnicontact extends Generic\AbstractEntity {

  /**
   * OmnimailJobProgress Check.
   *
   * @param bool $checkPermissions
   *
   * @return \Civi\Api4\Action\OmniContact\Get
   */
  public static function get(bool $checkPermissions = TRUE): Get {
    return (new Get(__CLASS__, __FUNCTION__))
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

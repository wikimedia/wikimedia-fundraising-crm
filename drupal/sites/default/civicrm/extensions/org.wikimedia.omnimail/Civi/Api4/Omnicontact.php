<?php
namespace Civi\Api4;

use Civi\Api4\Action\Omnicontact\Upload;
use Civi\Api4\Generic\BasicGetFieldsAction;
use Civi\Api4\Action\Omnicontact\Create;
use Civi\Api4\Action\Omnicontact\Get;
use Civi\Api4\Action\Omnicontact\Snooze;

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
   * Omnicontact create.
   *
   * Add or update an Acoustic recipient.
   *
   * @param bool $checkPermissions
   *
   * @return \Civi\Api4\Action\OmniContact\Create
   */
  public static function create(bool $checkPermissions = TRUE): Create {
    return (new Create(__CLASS__, __FUNCTION__))
      ->setCheckPermissions($checkPermissions);
  }

  /**
   * Omnicontact upload.
   *
   * Add or update an Acoustic recipient.
   *
   * @param bool $checkPermissions
   *
   * @return \Civi\Api4\Action\OmniContact\Upload
   */
  public static function upload(bool $checkPermissions = TRUE): Upload {
    return (new Upload(__CLASS__, __FUNCTION__))
      ->setCheckPermissions($checkPermissions);
  }

  /**
   * Omnicontact snooze.
   *
   * Add or update an Acoustic recipient.
   *
   * @param bool $checkPermissions
   *
   * @return \Civi\Api4\Action\OmniContact\Snooze
   */
  public static function snooze(bool $checkPermissions = TRUE): Snooze {
    return (new Snooze(__CLASS__, __FUNCTION__))
      ->setCheckPermissions($checkPermissions);
  }


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
  * Per https://phabricator.wikimedia.org/T305505 it seems we
  * want all Civi users to be able to access get info.
  *
  * @return array
  */
  public static function permissions():array {
    return ['default' => 'administer CiviCRM', 'get' => 'access CiviCRM'];
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

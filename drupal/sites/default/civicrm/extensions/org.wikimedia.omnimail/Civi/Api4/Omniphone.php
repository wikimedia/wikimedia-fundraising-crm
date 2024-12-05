<?php
namespace Civi\Api4;

use Civi\Api4\Action\Omniphone\BatchUpdate;
use Civi\Api4\Action\Omniphone\Update;
use Civi\Api4\Generic\BasicGetFieldsAction;

/**
*  Class OmniContact.
*
* Provided by the omnimail extension.
*
* @see https://developer.goacoustic.com/acoustic-campaign/reference/add-a-contact
* @package Civi\Api4
*/
class Omniphone extends Generic\AbstractEntity {

  /**
   * Omniphone Update.
   *
   * Batch action that finds and updates phones that have come in with only a recipient ID.
   *
   * The url would have recipient_id=12345 and we save this against the phone and then later
   * contact Acoustic for the actual number.
   *
   * @param bool $checkPermissions
   *
   * @return \Civi\Api4\Action\Omniphone\Update
   */
  public static function update(bool $checkPermissions = TRUE): Update {
    return (new Update(__CLASS__, __FUNCTION__))
      ->setCheckPermissions($checkPermissions);
  }

  /**
   * Omniphone batch Update.
   *
   * Batch action that finds and updates phones that have come in with only a recipient ID.
   *
   * The url would have recipient_id=12345 and we save this against the phone and then later
   * contact Acoustic for the actual number.
   *
   * @param bool $checkPermissions
   *
   * @return \Civi\Api4\Action\Omniphone\BatchUpdate
   */
  public static function batchUpdate(bool $checkPermissions = TRUE): BatchUpdate {
    return (new BatchUpdate(__CLASS__, __FUNCTION__))
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
    return ['default' => 'administer CiviCRM'];
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

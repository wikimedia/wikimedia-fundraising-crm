<?php
namespace Civi\Api4;

use Civi\Api4\Action\WMFContact\GetDonorSummary;
use Civi\Api4\Action\WMFContact\Save;
use Civi\Api4\Action\WMFContact\UpdateCommunicationsPreferences;
use Civi\Api4\Generic\BasicGetFieldsAction;

/**
 * Class WMF Data management.
 *
 * Api entity for WMF specific data cleanups.
 *
 * @package Civi\Api4
 */
class WMFContact extends Generic\AbstractEntity {

  /**
   * Save a contact from wmf 'msg' formatted array.
   *
   * @param bool $checkPermissions
   *
   * @return \Civi\Api4\Action\WMFContact\Save
   */
  public static function save(bool $checkPermissions = TRUE): Save {
    return (new Save(__CLASS__, __FUNCTION__))
      ->setCheckPermissions($checkPermissions);
  }

  /**
   * Update Email Preferences.
   *
   * @param bool $checkPermissions
   *
   * @return \Civi\Api4\Action\WMFContact\UpdateCommunicationsPreferences
   *
   */
  public static function updateCommunicationsPreferences(bool $checkPermissions = FALSE): UpdateCommunicationsPreferences {
    return (new UpdateCommunicationsPreferences(__CLASS__, __FUNCTION__))
      ->setCheckPermissions($checkPermissions);
  }

  /**
   * Get contact and donation information (for all contacts sharing an email with this CID)
   *
   * @param bool $checkPermissions
   * @return GetDonorSummary
   */
  public static function getDonorSummary(bool $checkPermissions = TRUE): GetDonorSummary {
    return (new GetDonorSummary(__CLASS__, __FUNCTION__))
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
    return ['save' => 'edit all contacts'];
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

<?php
namespace Civi\Api4;

use Civi\Api4\Action\WMFDataManagement\ArchiveThankYou;
use Civi\Api4\Action\WMFDataManagement\CleanInvalidLanguageOptions;
use Civi\Api4\Action\WMFDataManagement\UpdateWMFDonor;
use Civi\Api4\Generic\BasicGetFieldsAction;

/**
 * Class WMF Data management.
 *
 * Api entity for WMF specific data cleanups.
 *
 * @package Civi\Api4
 */
class WMFDataManagement extends Generic\AbstractEntity {

  /**
   * Archive thank you emails.
   *
   * This removes the details field from old thank you emails.
   *
   * @param bool $checkPermissions
   *
   * @return \Civi\Api4\Action\WMFDataManagement\ArchiveThankYou
   *
   */
  public static function archiveThankYou(bool $checkPermissions = TRUE): ArchiveThankYou {
    return (new ArchiveThankYou(__CLASS__, __FUNCTION__))
      ->setCheckPermissions($checkPermissions);
  }

  /**
   * Archive thank you emails.
   *
   * This removes the details field from old thank you emails.
   *
   * @param bool $checkPermissions
   *
   * @return \Civi\Api4\Action\WMFDataManagement\UpdateWMFDonor
   */
  public static function updateWMFDonor(bool $checkPermissions = TRUE): UpdateWMFDonor {
    return (new UpdateWMFDonor(__CLASS__, __FUNCTION__))
      ->setCheckPermissions($checkPermissions);
  }

  /**
   * Clean up unused languages.
   *
   * @param bool $checkPermissions
   *
   * @return \Civi\Api4\Action\WMFDataManagement\CleanInvalidLanguageOptions
   */
  public static function CleanInvalidLanguageOptions(bool $checkPermissions = TRUE): CleanInvalidLanguageOptions {
    return (new CleanInvalidLanguageOptions(__CLASS__, __FUNCTION__))
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

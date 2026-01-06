<?php
namespace Civi\Api4;

use Civi\Api4\Action\WMFContact\BackfillOptIn;
use Civi\Api4\Action\WMFContact\GetCommunicationsPreferences;
use Civi\Api4\Action\WMFContact\GetDonorSummary;
use Civi\Api4\Action\WMFContact\Save;
use Civi\Api4\Action\WMFContact\UpdateCommunicationsPreferences;
use Civi\Api4\Action\WMFContact\DoubleOptIn;
use Civi\Api4\Action\WMFContact\BulkEmailable;
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
   * Get email preferences
   *
   * @param bool $checkPermissions
   * @return GetCommunicationsPreferences
   */
  public static function getCommunicationsPreferences(bool $checkPermissions = TRUE): GetCommunicationsPreferences {
    return (new GetCommunicationsPreferences(__CLASS__, __FUNCTION__))
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
   * Backfill opt_in value from log message (for all contacts with a given email)
   *
   * @param bool $checkPermissions
   * @return BackfillOptIn
   */
  public static function backfillOptIn(bool $checkPermissions = FALSE): BackfillOptIn {
    return (new BackfillOptIn(__CLASS__, __FUNCTION__))
      ->setCheckPermissions($checkPermissions);
  }

  /**
   * Verify and record a double opt-in activity
   *
   * @param bool $checkPermissions
   * @return \Civi\Api4\Action\WMFContact\DoubleOptIn
   */
  public static function doubleOptIn(bool $checkPermissions = FALSE): DoubleOptIn {
    return (new DoubleOptIn(__CLASS__, __FUNCTION__))
      ->setCheckPermissions($checkPermissions);
  }

  /**
   * Check if an email can receive bulk emails
   *
   * @param bool $checkPermissions
   * @return \Civi\Api4\Action\WMFContact\BulkEmailable
   */
  public static function bulkEmailable(bool $checkPermissions = FALSE): BulkEmailable {
    return (new BulkEmailable(__CLASS__, __FUNCTION__))
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
    return [
      'getCommunicationsPreferences' => '*always allow*',
      'getDonorSummary' => '*always allow*',
      'doubleOptIn' => '*always allow*',
      'save' => 'edit all contacts',
    ];
  }

  public static function getFields(bool $checkPermissions = TRUE): BasicGetFieldsAction {
    return (new BasicGetFieldsAction(__CLASS__, __FUNCTION__, function() {
      return [];
    }))->setCheckPermissions($checkPermissions);
  }

}

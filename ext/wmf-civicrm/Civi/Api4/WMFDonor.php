<?php

namespace Civi\Api4;

use Civi\Api4\Action\WMFDonor\Get;
use Civi\Api4\Action\WMFDonor\Update;
use Civi\Api4\Generic\BasicGetFieldsAction;
use Civi\WMFHook\CalculatedData;

/**
 * Class WMF Donor.
 *
 * Api entity for WMF Donor calculations.
 *
 * @package Civi\Api4
 */
class WMFDonor extends Generic\AbstractEntity {

  /**
   * Get the values that would be calculated for a WMF Donor.
   *
   * @param bool $checkPermissions
   *
   * @return \Civi\Api4\Action\WMFDonor\Get
   */
  public static function get(bool $checkPermissions = TRUE): Get {
    return (new Get(__CLASS__, __FUNCTION__))
      ->setCheckPermissions($checkPermissions);
  }

  /**
   * Update wmf donor values for the relevant donors.
   *
   * @param bool $checkPermissions
   *
   * @return \Civi\Api4\Action\WMFDonor\Update
   */
  public static function update(bool $checkPermissions = TRUE): Update {
    return (new Update(__CLASS__, __FUNCTION__))
      ->setCheckPermissions($checkPermissions);
  }

  /**
   * Get permissions.
   *
   * @return array
   */
  public static function permissions(): array {
    return [
      'get' => 'view all contacts',
      'create' => 'edit all contacts',
      'update' => 'edit all contacts',
      'default' => ['access CiviCRM'],
    ];
  }

  /**
   * @param bool $checkPermissions
   *
   * @return Generic\BasicGetFieldsAction
   */
  public static function getFields(bool $checkPermissions = TRUE): BasicGetFieldsAction {
    return (new BasicGetFieldsAction(__CLASS__, __FUNCTION__, (static function() {
      return array_merge(['id' => ['name' => 'id', 'title' => 'Contact ID']], (new CalculatedData())->getWMFDonorFields());
    })))->setCheckPermissions($checkPermissions);
  }

}
